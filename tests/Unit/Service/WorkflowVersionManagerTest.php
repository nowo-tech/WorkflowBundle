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
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.19 (REQ-ARCH-001 rule 1; the only versioning port since 2.0): every method delegates to the internal
 * {@see WorkflowVersioning} with the stored definition the reference names, and answers with value objects only. The
 * write flows run end to end in ArchPortsKernelTest (a real store); here the reads, the plain queries and the refusal
 * of references that name no stored version.
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
        $manager = new WorkflowVersionManager(new WorkflowVersioning($repository), $repository);

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
    public function thePlainQueriesDelegate(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $retired    = $this->leave($tenant, 7, 2)->retire();
        $repository = $this->repository();
        $repository->method('findVersions')->with('leave', $tenant)->willReturn([$retired]);

        $manager = new WorkflowVersionManager(new WorkflowVersioning($repository), $repository);
        self::assertFalse($manager->isLockEnabled());
        self::assertTrue($manager->isRetiredScope('leave', $tenant));
        self::assertSame(0, $manager->forgetSubject(new WorkflowSubjectRef('leave-1'), $tenant), 'no pin store: nothing to forget');

        self::assertTrue((new WorkflowVersionManager(new WorkflowVersioning($repository, lockCloneStructure: true), $repository))->isLockEnabled());
    }

    #[Test]
    public function aReferenceWithNoVersionInItsScopeIsRefused(): void
    {
        $repository = $this->repository();
        $repository->method('findVersion')->willReturn(null);
        $repository->expects(self::never())->method('remove');
        $manager = new WorkflowVersionManager(new WorkflowVersioning($repository), $repository);

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
        $repository->expects(self::never())->method('save');
        $manager = new WorkflowVersionManager(new WorkflowVersioning($repository), $repository);

        try {
            $manager->publish(new WorkflowDefinitionRef(8, 'leave', $tenant, 2));
            self::fail('A reference whose id is not the stored one is refused.');
        } catch (WorkflowNotFoundException $exception) {
            self::assertStringContainsString('"leave"', $exception->getMessage());
        }
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
