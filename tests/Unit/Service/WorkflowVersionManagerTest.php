<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\FakeVersioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.19 (REQ-ARCH-001 rule 1): the framework-free versioning twin. Every method delegates to the versioning SPI with
 * the stored definition the reference names, and answers with value objects only.
 */
final class WorkflowVersionManagerTest extends TestCase
{
    #[Test]
    public function theReadsAnswerWithViewsOfTheStore(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $stored     = $this->leave($tenant, 7, 2);
        $repository = $this->repository();
        $repository->method('findCurrent')->willReturnOnConsecutiveCalls($stored, null);
        $repository->method('findDraft')->willReturnOnConsecutiveCalls($stored, null);
        $repository->method('findVersions')->willReturn([$stored]);
        $manager = new WorkflowVersionManager(new FakeVersioning($stored), $repository);

        $current = $manager->currentVersion('leave', $tenant);
        self::assertNotNull($current);
        self::assertSame(7, $current->ref->id);
        self::assertSame(2, $current->ref->version);
        self::assertEquals($tenant, $current->ref->tenant);
        self::assertNull($manager->currentVersion('leave', $tenant));

        $draft = $manager->draftVersion('leave', $tenant);
        self::assertNotNull($draft);
        self::assertSame('leave', $draft->ref->slug);
        self::assertNull($manager->draftVersion('leave', $tenant));

        $versions = $manager->versions('leave', $tenant);
        self::assertCount(1, $versions);
        self::assertSame(7, $versions[0]->ref->id);
    }

    #[Test]
    public function theScopeCommandsDelegateWithTheSlugAndTenant(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $stored     = $this->leave($tenant, 7, 2);
        $fake       = new FakeVersioning($stored);
        $repository = $this->repository();
        $repository->expects(self::never())->method('findVersion');
        $manager = new WorkflowVersionManager($fake, $repository);

        self::assertSame(7, $manager->cloneTemplate('leave', $tenant, publish: true)->ref->id);
        self::assertSame(7, $manager->createDraft('leave', $tenant)->ref->id);
        self::assertSame(7, $manager->publishDraft('leave', null)->ref->id);

        $fake->lockEnabled = true;
        self::assertTrue($manager->isLockEnabled());
        $fake->retiredScope = true;
        self::assertTrue($manager->isRetiredScope('leave', $tenant));
        $fake->forgotten = 3;
        self::assertSame(3, $manager->forgetSubject(new WorkflowSubjectRef('leave-1'), $tenant));
        self::assertEquals(new WorkflowSubjectRef('leave-1'), $fake->forgottenSubject);

        self::assertSame(
            ['cloneTemplate:published', 'createDraft', 'publishDraft', 'isLockEnabled', 'isRetiredScope', 'forgetSubject'],
            $fake->calls,
        );
        self::assertSame([['leave', $tenant], ['leave', $tenant], ['leave', null], ['leave', $tenant], ['', $tenant]], $fake->scopes);
    }

    #[Test]
    public function theReferenceCommandsDelegateWithTheStoredDefinition(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $stored     = $this->leave($tenant, 7, 2);
        $fake       = new FakeVersioning($stored);
        $repository = $this->repository();
        $repository->method('findVersion')->with('leave', $tenant, 2)->willReturn($stored);
        $manager   = new WorkflowVersionManager($fake, $repository);
        $reference = new WorkflowDefinitionRef(7, 'leave', $tenant, 2);

        self::assertSame(7, $manager->createDraftOf($reference)->ref->id);
        self::assertSame(7, $manager->publish($reference)->ref->id);
        $fake->structureLocked = true;
        self::assertTrue($manager->isStructureLocked($reference));
        $manager->assertStructureAllowed($reference);
        self::assertSame(7, $manager->retire($reference)->ref->id);
        self::assertSame(7, $manager->reinstate($reference)->ref->id);
        $manager->delete($reference);

        self::assertSame(
            ['createDraftOf', 'publish', 'isStructureLocked', 'assertStructureAllowed', 'retire', 'reinstate', 'delete'],
            $fake->calls,
        );
        self::assertSame([$stored, $stored, $stored, $stored, $stored, $stored, $stored], $fake->definitions);
    }

    #[Test]
    public function aReferenceWithNoVersionInItsScopeIsRefused(): void
    {
        $repository = $this->repository();
        $repository->method('findVersion')->willReturn(null);
        $manager = new WorkflowVersionManager(new FakeVersioning($this->leave(null, 7, 1)), $repository);

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage('Workflow definition "leave" was not found or is disabled.');
        $manager->delete(new WorkflowDefinitionRef(7, 'leave', new WorkflowTenant('org-a'), 2));
    }

    #[Test]
    public function aReferenceWhoseIdIsNotTheStoredOneIsRefused(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('findVersion')->willReturn($this->leave($tenant, 7, 2));
        $fake    = new FakeVersioning($this->leave($tenant, 7, 2));
        $manager = new WorkflowVersionManager($fake, $repository);

        try {
            $manager->publish(new WorkflowDefinitionRef(8, 'leave', $tenant, 2));
            self::fail('A reference whose id is not the stored one is refused.');
        } catch (WorkflowNotFoundException $exception) {
            self::assertStringContainsString('"leave"', $exception->getMessage());
        }
        self::assertSame([], $fake->calls, 'the SPI is never asked');
    }

    /**
     * @return MockObject&WorkflowDefinitionRepository
     */
    private function repository(): MockObject
    {
        return $this->createMock(WorkflowDefinitionRepository::class);
    }

    /**
     * A stored "leave" definition with the given row id and version number.
     */
    private function leave(?WorkflowTenant $tenant, int $id, int $version): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave approval', 'leave', 'requested', 'App\\Entity\\LeaveRequest');
        $definition->addPlace(new WorkflowPlace('requested'));
        $definition->addPlace(new WorkflowPlace('approved'));
        $definition->addTransition(new WorkflowTransition('approve', ['requested'], ['approved']));
        if ($tenant instanceof WorkflowTenant) {
            $definition->startAsDraft($tenant)->publish();
        }
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, $id);
        (new ReflectionProperty(WorkflowDefinition::class, 'version'))->setValue($definition, $version);

        return $definition;
    }
}
