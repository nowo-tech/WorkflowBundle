<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Audit\AuditMiddleware;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\EventListener\TenantIsolationGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\TransitionAuditSubscriber;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Workflow\Event\Event;

use function sys_get_temp_dir;

/**
 * Without the tenant resolver (and without guard ports) 1.15 behaves as 1.14: the tenancy and audit services are
 * not registered, the applicator dispatches exactly the workflow events the 1.14 applicator did (each guard once per
 * apply), definitions stay global, version 1, published, and the UI shows no tenancy action or field.
 */
final class NoResolverEquivalenceKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private ?TenancyTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    #[Test]
    public function theTenancyServicesAreAbsentAndTheEventSequenceIsThe114One(): void
    {
        $this->kernel = $kernel = self::boot('plain');
        $container    = self::container($kernel);
        foreach ([TenantIsolationGuardSubscriber::class, AfterCommitDispatcher::class, AuditMiddleware::class, TransitionAuditSubscriber::class] as $absent) {
            self::assertFalse($container->has($absent), $absent);
        }
        self::assertFalse($kernel->getContainer()->getParameter('nowo_workflow.tenancy.enabled'));
        self::assertFalse($kernel->getContainer()->getParameter('nowo_workflow.audit.enabled'));

        // A 1.14 definition: no guard, no automatic transition.
        $definition = new WorkflowDefinition('Leave approval', 'leave', 'requested', LeaveRequest::class);
        foreach (['requested', 'submitted', 'approved'] as $index => $place) {
            $definition->addPlace(new WorkflowPlace($place, null, $index));
        }
        $definition->addTransition(new WorkflowTransition('submit', ['requested'], ['submitted']));
        $definition->addTransition(new WorkflowTransition('approve', ['submitted'], ['approved']));
        self::repository($kernel)->save($definition);
        self::assertSame([1, '', false], [$definition->getVersion(), $definition->getTenantKey(), $definition->isDraft()]);

        $events     = [];
        $dispatcher = self::service($kernel, EventDispatcherInterface::class, 'event_dispatcher');
        foreach (['guard', 'leave', 'transition', 'enter', 'entered', 'completed', 'announce'] as $name) {
            $dispatcher->addListener('workflow.' . $name, static function (Event $event, string $eventName) use (&$events): void {
                $events[] = $eventName . ':' . $event->getSubject()::class;
            });
        }

        // 1.14 path: the registry's workflow applied directly (WorkflowApplicator 1.14 did exactly this, then flushed).
        $legacy = self::persistLeave($kernel, new LeaveRequest('leave-1', '', 5));
        self::registry($kernel)->get('leave')->apply($legacy, 'submit');
        self::hostManager($kernel)->flush();
        $expected = $events;
        $events   = [];

        $current = self::persistLeave($kernel, new LeaveRequest('leave-2', '', 5));
        self::applicator($kernel)->apply($current, 'leave', 'submit');

        self::assertSame($expected, $events, 'the same workflow events, in the same order');
        self::assertSame($legacy->getStatus(), $current->getStatus());
        self::assertSame(
            self::registry($kernel)->get('leave')->getMetadataStore()->getWorkflowMetadata(),
            self::registry($kernel)->forSubject($current, 'leave')->getMetadataStore()->getWorkflowMetadata(),
            'forSubject() is get() without tenancy',
        );
    }

    #[Test]
    public function theUiHasNoTenancyActionOrField(): void
    {
        $this->kernel = $kernel = self::boot('plain');
        $definition   = self::seedLeaveTemplate($kernel);
        $id           = (int) $definition->getId();

        $list = (string) self::send($kernel, '/workflow/definitions', 'root')->getContent();
        self::assertStringNotContainsString('workflow-badge', $list);
        $show = (string) self::send($kernel, '/workflow/definitions/' . $id, 'root')->getContent();
        self::assertStringContainsString('/workflow/definitions/' . $id . '/edit/general', $show);
        self::assertStringNotContainsString('/publish', $show);
        self::assertStringNotContainsString('/draft', $show);
        self::assertStringNotContainsString('guardExpression', (string) self::send($kernel, '/workflow/definitions/' . $id . '/edit/transitions', 'root')->getContent());
        $duplicate = self::send($kernel, '/workflow/definitions/new', 'root', 'POST', ['workflow_definition_form' => [
            'name'            => 'Leave again',
            'slug'            => 'leave',
            'type'            => 'state_machine',
            'initialPlace'    => 'requested',
            'subjectClass'    => LeaveRequest::class,
            'markingProperty' => 'status',
            'priority'        => '0',
            '_token'          => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(500, $duplicate->getStatusCode(), 'a duplicate slug fails in the database, as in 1.14');
        foreach (['draft', 'publish', 'clone'] as $action) {
            self::assertSame(404, self::send($kernel, '/workflow/definitions/' . $id . '/' . $action, 'root', 'POST')->getStatusCode(), $action);
        }
    }
}
