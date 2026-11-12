<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\MiscSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Workflow\Event\GuardEvent;

use function sort;
use function sys_get_temp_dir;

/**
 * Less travelled paths of 1.15: explicit automatic run, a guard that changes its mind, pinned versions through the
 * context and subject resolvers, unpinnable subjects, versioning errors, the request memo and UI edge cases.
 */
final class TenancyEdgeCasesKernelTest extends TestCase
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
    public function automaticTransitionsOnDemandAndAGuardThatChangesItsMind(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 1));
        $leave->setStatus('submitted');

        self::assertSame(['auto_approve'], self::applicator($kernel)->applyAutomaticTransitions($leave));
        self::assertSame('approved', $leave->getStatus());

        $calls = 0;
        self::service($kernel, EventDispatcherInterface::class, 'event_dispatcher')->addListener('workflow.leave.guard.auto_approve', static function (GuardEvent $event) use (&$calls): void {
            if (++$calls > 1) {
                $event->setBlocked(true, 'changed its mind');
            }
        });
        $other = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 1));
        $other->setStatus('submitted');
        self::assertSame([], self::applicator($kernel)->applyAutomaticTransitions($other, 'leave'));
        self::assertSame('submitted', $other->getStatus());
    }

    #[Test]
    public function pinnedVersionsWinThroughEveryResolutionPath(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $template     = new WorkflowDefinition('Leave approval', 'leave', 'requested', LeaveRequest::class);
        $template->addPlace(new WorkflowPlace('requested'));
        $template->addPlace(new WorkflowPlace('submitted', null, 1));
        $template->addTransition(new WorkflowTransition('submit', ['requested'], ['submitted']));
        self::repository($kernel)->save($template);
        $a     = new WorkflowTenant('org-a');
        $v1    = self::versioning($kernel)->cloneTemplate('leave', $a, true);
        $ruled = new WorkflowDefinition('Ruled', 'ruled', 'requested', LeaveRequest::class);
        $ruled->addMatchRule(new WorkflowMatchRule('kind', 'leave'));
        $ruled->addPlace(new WorkflowPlace('requested'));
        self::repository($kernel)->save($ruled);
        self::assertSame(['kind' => 'leave'], self::versioning($kernel)->cloneTemplate('ruled', $a)->getMatchParameters(), 'match rules are copied');

        $x = self::persistLeave($kernel, new LeaveRequest('leave-x', 'org-a', 5));
        self::applicator($kernel)->applyByContext($x, new WorkflowContext(), 'submit');
        $draft = self::versioning($kernel)->createDraft('leave', $a);
        $draft->addTransition(new WorkflowTransition('withdraw', ['submitted'], ['requested']));
        self::repository($kernel)->save($draft);
        self::versioning($kernel)->publishDraft('leave', $a);

        self::assertSame([], self::applicator($kernel)->getEnabledTransitionsByContext($x, new WorkflowContext()), 'v1 has no withdraw');
        self::assertSame($v1->getId(), self::applicator($kernel)->resolveForSubject($x)->getId());
        $y = self::persistLeave($kernel, new LeaveRequest('leave-y', 'org-a', 5));
        $y->setStatus('submitted');
        self::assertSame(['withdraw'], self::applicator($kernel)->getEnabledTransitionsForSubject($y));

        $pin = self::hostManager($kernel)->getRepository(WorkflowSubjectPin::class)->findOneBy(['subjectRef' => 'leave-x']);
        self::assertInstanceOf(WorkflowSubjectPin::class, $pin);
        self::assertSame(['org-a', 'leave'], [$pin->getTenantKey(), $pin->getWorkflowSlug()]);
        self::assertNotNull($pin->getId());
        self::assertLessThanOrEqual(time(), $pin->getCreatedAt()->getTimestamp());

        self::assertSame(0, self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-x'), new WorkflowTenant('org-b')), 'another tenant: nothing');
        self::assertSame(1, self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-x'), $a));
        self::assertSame(0, self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-x'), $a));
        self::assertSame(0, (new WorkflowVersioning(self::repository($kernel)))->forgetSubject(new WorkflowSubjectRef('leave-x'), $a), 'no pin store');
    }

    #[Test]
    public function aSubjectWithoutReferenceIsNeverPinned(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $misc         = new WorkflowDefinition('Misc', 'misc', 'a', MiscSubject::class);
        $misc->addPlace(new WorkflowPlace('a'));
        $misc->addPlace(new WorkflowPlace('b', null, 1));
        $misc->addTransition(new WorkflowTransition('go', ['a'], ['b']));
        self::repository($kernel)->save($misc);

        $subject = new MiscSubject();
        self::applicator($kernel)->apply($subject, 'misc', 'go');
        self::assertSame('b', $subject->getStatus());
        self::assertSame(0, (int) self::hostManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_subject_pin'));
    }

    #[Test]
    public function versioningRefusesWhatDoesNotExist(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        foreach ([
            static fn (): WorkflowDefinition => self::versioning($kernel)->cloneTemplate('missing', new WorkflowTenant('org-a')),
            static fn (): WorkflowDefinition => self::versioning($kernel)->createDraft('missing', null),
            static fn (): WorkflowDefinition => self::versioning($kernel)->publishDraft('missing', null),
        ] as $index => $call) {
            try {
                $call();
                self::fail('#' . $index);
            } catch (WorkflowNotFoundException) {
            }
        }

        $definition = self::seedLeaveTemplate($kernel);
        $stack      = self::service($kernel, RequestStack::class, 'request_stack');
        $stack->push(new Request());
        self::assertSame(self::registry($kernel)->forDefinition($definition), self::registry($kernel)->forDefinition($definition), 'memoized for the request');
        $stack->pop();
    }

    #[Test]
    public function uiEdgeCases(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $global       = self::seedLeaveTemplate($kernel);
        $own          = self::seedLeaveTemplate($kernel, 'days <= 1', new WorkflowTenant('org-a'));
        $g            = (int) $global->getId();
        $o            = (int) $own->getId();

        self::assertSame(404, self::send($kernel, self::ui('global', '/definitions/' . $g . '/clone'), 'root', 'POST', ['_token' => self::csrf($kernel, 'clone' . $g)])->getStatusCode(), 'no tenant to clone into');
        self::assertStringContainsString('/definitions/' . $o . '/draft', (string) self::send($kernel, self::ui('org-a', '/definitions/' . $o), 'admin-org-a')->getContent(), 'a published version offers a new draft');
        self::assertStringContainsString('/definitions/' . $o . '/draft', (string) self::send($kernel, self::ui('org-a', '/definitions'), 'admin-org-a')->getContent());

        $draft = self::versioning($kernel)->createDraft('leave', new WorkflowTenant('org-a'));
        $id    = (int) $draft->getId();
        $names = [];
        foreach ($draft->getTransitions() as $transition) {
            $names[] = $transition->getName();
        }
        sort($names);
        $transitions = [];
        foreach ($draft->getTransitions() as $index => $transition) {
            $transitions[$index] = [
                'name'            => $transition->getName(),
                'fromPlaces'      => $transition->getFromPlaces(),
                'toPlaces'        => $transition->getToPlaces(),
                'guardExpression' => $transition->getName() === 'auto_approve' ? 'subject.getDays() <= 2' : '',
            ];
        }
        $response = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/transitions'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'transitions' => $transitions,
            '_token'      => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(422, $response->getStatusCode(), 'an expression outside the language is refused on save');
        self::assertStringContainsString('Invalid character', (string) $response->getContent());

        foreach ($transitions as $index => $transition) {
            if ($transition['name'] === 'auto_approve') {
                $transitions[$index]['guardExpression'] = 'weekday == 1';
            }
        }
        $response = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/transitions'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'transitions' => $transitions,
            '_token'      => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(422, $response->getStatusCode(), 'a variable outside the whitelist is refused');

        foreach ($transitions as $index => $transition) {
            $transitions[$index]['guardExpression'] = $transition['name'] === 'auto_approve' ? 'days <= 3' : '';
        }
        $saved = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/transitions'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'transitions' => $transitions,
            '_token'      => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(302, $saved->getStatusCode(), (string) $saved->getContent());

        $this->actAs();
    }

    private function actAs(): void
    {
        self::service($this->kernel ?? throw new LogicException(), TokenStorageInterface::class, 'security.token_storage')
            ->setToken(new UsernamePasswordToken(new InMemoryUser('nobody', null), 'main'));
    }
}
