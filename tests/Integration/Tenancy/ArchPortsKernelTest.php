<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowSlugResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\FixedSlugResolver;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\RecordingVersioningDecorator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 1.17 (REQ-ARCH-001 rule 1): the versioning and slug-resolver ports. The interfaces resolve to the built-in services
 * (same objects, same answers as 1.16), a host can decorate or replace them, and the admin UI goes through the
 * versioning port. The owner rule on deletes (no delete while subjects are pinned) holds behind a decorator.
 *
 * 1.19 (REQ-ARCH-001 rule 1): the framework-free versioning twin {@see WorkflowVersionManagerInterface} runs the same
 * flows with references and views only, and a host decorator of the deprecated SPI is honoured through it.
 */
final class ArchPortsKernelTest extends TestCase
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function builtInShapes(): iterable
    {
        yield 'tenancy' => ['tenancy'];
        yield 'DDD host, tenancy' => ['tenancy-ddd'];
        yield 'no tenancy' => ['plain'];
    }

    #[Test]
    #[DataProvider('builtInShapes')]
    public function thePortsAreTheBuiltInServices(string $variant): void
    {
        $this->kernel = $kernel = self::boot($variant);

        self::assertSame(
            self::service($kernel, WorkflowVersioning::class, 'test.' . WorkflowVersioning::class),
            self::service($kernel, WorkflowVersioningInterface::class, 'test.' . WorkflowVersioningInterface::class),
        );
        self::assertSame(
            self::service($kernel, WorkflowResolver::class, 'test.' . WorkflowResolver::class),
            self::service($kernel, WorkflowSlugResolverInterface::class, 'test.' . WorkflowSlugResolverInterface::class),
        );
    }

    #[Test]
    public function theSlugResolverAnswersLikeTheResolver(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $port     = self::service($kernel, WorkflowSlugResolverInterface::class, 'test.' . WorkflowSlugResolverInterface::class);
        $resolver = self::service($kernel, WorkflowResolver::class, 'test.' . WorkflowResolver::class);
        $context  = new WorkflowContext(subjectClass: LeaveRequest::class);

        self::assertSame('leave', $port->resolveSlug($context));
        self::assertSame($resolver->resolve($context)->getSlug(), $port->resolveSlug($context));

        // With tenancy the subject's tenant version wins: same slug, and the same definition as the resolver.
        self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'), publish: true);
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 3));
        self::assertSame('leave', $port->resolveSlugForSubject($leave));
        self::assertSame($resolver->resolveForSubject($leave)->getSlug(), $port->resolveSlugForSubject($leave));
        self::assertEquals(new WorkflowTenant('org-a'), $resolver->resolveForSubject($leave)->getTenant());
    }

    #[Test]
    public function lintContainerPassesWithHostPorts(): void
    {
        $this->kernel = new TenancyTestKernel('tenancy-host-ports', true);
        $this->kernel->boot();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }

    #[Test]
    public function aHostDecoratorOfTheVersioningPortRunsForHostsAndForTheUi(): void
    {
        $this->kernel = $kernel = self::boot('tenancy-host-ports');
        $port         = self::service($kernel, WorkflowVersioningInterface::class, 'test.' . WorkflowVersioningInterface::class);
        $decorator    = self::service($kernel, RecordingVersioningDecorator::class, 'app.workflow_versioning_audit');
        self::assertSame($decorator, $port);
        self::assertInstanceOf(FixedSlugResolver::class, self::service($kernel, WorkflowSlugResolverInterface::class, 'test.' . WorkflowSlugResolverInterface::class));

        self::seedLeaveTemplate($kernel);
        $orgA  = new WorkflowTenant('org-a');
        $clone = $port->cloneTemplate('leave', $orgA, publish: true);
        $id    = (int) $clone->getId();
        self::assertSame(['cloneTemplate'], $decorator->calls);

        // The admin UI calls the port, so it goes through the host decorator.
        $retire = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/retire'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'retire' . $id)]);
        self::assertSame(302, $retire->getStatusCode());
        self::assertContains('retire', $decorator->calls);
        self::assertTrue(self::repository($kernel)->find($id)?->isRetired());
        $decorator->calls = [];

        // Owner decision 2026-11-12, unchanged: a definition subjects are pinned to is not deleted, decorator or not.
        $port->reinstate(self::repository($kernel)->find($id));
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        $current = self::repository($kernel)->findCurrent('leave', $orgA);
        self::assertNotNull($current);
        try {
            $port->delete($current);
            self::fail('Pinned subjects block the deletion.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(1, $exception->pinnedSubjects);
        }
        self::assertNotNull(self::repository($kernel)->findCurrent('leave', $orgA));

        self::assertSame(1, $port->forgetSubject(new WorkflowSubjectRef('leave-1'), $orgA));
        $port->delete($current);
        self::assertNull(self::repository($kernel)->findCurrent('leave', $orgA));
        self::assertSame(['reinstate', 'delete', 'forgetSubject', 'delete'], $decorator->calls);
    }

    #[Test]
    #[DataProvider('builtInShapes')]
    public function theVersioningTwinIsTheBuiltInFacade(string $variant): void
    {
        $this->kernel = $kernel = self::boot($variant);

        self::assertInstanceOf(WorkflowVersionManager::class, self::versionManager($kernel));
        self::assertSame(
            self::service($kernel, WorkflowVersionManagerInterface::class, 'test.' . WorkflowVersionManagerInterface::class),
            self::versionManager($kernel),
        );
    }

    #[Test]
    public function theVersioningTwinRunsEveryFlowWithReferencesAndViews(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $manager = self::versionManager($kernel);
        $orgA    = new WorkflowTenant('org-a');

        self::assertSame([], $manager->versions('leave', $orgA), 'the tenant has no version of its own yet');
        self::assertFalse($manager->isLockEnabled());
        self::assertFalse($manager->isRetiredScope('leave', $orgA));

        $clone = $manager->cloneTemplate('leave', $orgA, publish: true);
        self::assertTrue($clone->isPublished());
        self::assertSame(1, $clone->ref->version);
        self::assertEquals($orgA, $clone->ref->tenant);
        self::assertEqualsCanonicalizing(['requested', 'submitted', 'approved', 'rejected'], $clone->placeNames);
        self::assertEqualsCanonicalizing(['approve', 'auto_approve', 'reject', 'submit'], $clone->transitionNames);
        self::assertFalse($manager->isStructureLocked($clone->ref));
        $manager->assertStructureAllowed($clone->ref);

        $draft = $manager->createDraftOf($clone->ref);
        self::assertTrue($draft->isDraft());
        self::assertSame(2, $draft->ref->version);
        $open = $manager->draftVersion('leave', $orgA);
        self::assertNotNull($open);
        self::assertSame($draft->ref->id, $open->ref->id);
        self::assertSame($draft->ref->id, $manager->createDraft('leave', $orgA)->ref->id, 'the open draft is reused');
        self::assertTrue($manager->publish($draft->ref)->isPublished());
        $published = $manager->currentVersion('leave', $orgA);
        self::assertNotNull($published);
        self::assertSame($draft->ref->id, $published->ref->id);

        $second = $manager->createDraft('leave', $orgA);
        self::assertTrue($manager->publishDraft('leave', $orgA)->isPublished());
        $republished = $manager->currentVersion('leave', $orgA);
        self::assertNotNull($republished);
        self::assertSame($second->ref->id, $republished->ref->id);
        self::assertCount(3, $manager->versions('leave', $orgA));

        self::assertTrue($manager->retire($second->ref)->isRetired());
        self::assertTrue($manager->isRetiredScope('leave', $orgA));
        self::assertTrue($manager->reinstate($second->ref)->isPublished());

        // Owner decision 2026-11-12, unchanged through the twin: pinned subjects block the deletion.
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        $current = $manager->versions('leave', $orgA)[0];
        try {
            $manager->delete($current->ref);
            self::fail('Pinned subjects block the deletion.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(1, $exception->pinnedSubjects);
        }
        self::assertSame(1, $manager->forgetSubject(new WorkflowSubjectRef('leave-1'), $orgA));
        $manager->delete($current->ref);
        self::assertSame([], $manager->versions('leave', $orgA), 'every version of the scope is gone');
    }

    #[Test]
    public function theVersioningTwinNeverReachesAnotherTenantAndGoesThroughAHostDecorator(): void
    {
        $this->kernel = $kernel = self::boot('tenancy-host-ports');
        self::seedLeaveTemplate($kernel);
        $manager   = self::versionManager($kernel);
        $decorator = self::service($kernel, RecordingVersioningDecorator::class, 'app.workflow_versioning_audit');

        $clone = $manager->cloneTemplate('leave', new WorkflowTenant('org-a'), publish: true);
        self::assertSame(['cloneTemplate'], $decorator->calls, 'the twin asks the decorated SPI');

        // Same row id and version number, another tenant: the scope lookup finds nothing.
        $this->expectException(WorkflowNotFoundException::class);
        $manager->delete(new WorkflowDefinitionRef($clone->ref->id, 'leave', new WorkflowTenant('org-b'), $clone->ref->version));
    }
}
