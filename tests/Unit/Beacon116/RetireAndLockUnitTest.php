<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Beacon116;

use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowDefinitionStatus;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 1.16: the retired status and the structure of a definition (unit level; the flows are in TenantClonesKernelTest).
 */
final class RetireAndLockUnitTest extends TestCase
{
    #[Test]
    public function onlyAPublishedVersionIsRetiredAndOnlyARetiredOneReinstated(): void
    {
        $definition = $this->leave(new WorkflowTenant('org-a'));
        self::assertTrue($definition->isPublished());
        self::assertTrue($definition->retire()->isRetired());
        self::assertFalse($definition->isPublished());
        self::assertSame(WorkflowDefinitionStatus::Retired, $definition->getStatus());
        self::assertTrue($definition->reinstate()->isPublished());

        try {
            $definition->reinstate();
            self::fail('A published version is not retired.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('is not retired', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not published');
        $definition->startAsDraft(new WorkflowTenant('org-a'))->retire();
    }

    #[Test]
    public function theStructureIgnoresLabelsGuardsAndOrder(): void
    {
        $a = $this->leave(null);
        $b = $this->leave(null);
        foreach ($b->getPlaces() as $place) {
            $place->setLabel('Other label')->setSortOrder(9);
        }
        foreach ($b->getTransitions() as $transition) {
            $transition->setGuardExpression('days <= 9')->setLabelTranslation('k', 'd')->setFromPlaces(['requested']);
        }
        self::assertSame($a->structure(), $b->structure());
        self::assertSame(['initial' => 'requested', 'places' => ['approved', 'requested'], 'transitions' => ['approve:requested>approved']], $a->structure());

        $b->addPlace(new WorkflowPlace('escalated'));
        self::assertNotSame($a->structure(), $b->structure());
    }

    #[Test]
    public function aCloneIsATenantDefinitionWithATemplateVersion(): void
    {
        $template = $this->leave(null);
        self::assertFalse($template->isTemplateClone());
        self::assertTrue($template->copyAsDraft(new WorkflowTenant('org-a'), 1, 3)->isTemplateClone());
        self::assertFalse($this->leave(new WorkflowTenant('org-a'))->isTemplateClone());
    }

    #[Test]
    public function aLockedCloneWhoseTemplateIsGoneCannotBePublished(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('existsInScope')->willReturn(false);
        $repository->method('findVersion')->willReturn(null);
        $repository->method('findCurrent')->willReturn(null);
        $repository->expects(self::never())->method('save');
        $clone = $this->leave(null)->copyAsDraft(new WorkflowTenant('org-a'), 1, 1);

        self::assertFalse((new WorkflowVersioning($repository))->isStructureLocked($clone), 'lock off');
        $versioning = new WorkflowVersioning($repository, lockCloneStructure: true);
        self::assertFalse($versioning->isStructureLocked($this->leave(null)), 'a global definition is never locked');
        self::assertFalse($versioning->isStructureLocked($this->leave(new WorkflowTenant('org-a'))), 'not a clone, no template of that slug');
        self::assertTrue($versioning->isStructureLocked($clone));

        $this->expectException(WorkflowStructureLockedException::class);
        $this->expectExceptionMessage('Workflow "leave" is locked to its template: its global template is gone.');
        $versioning->publish($clone);
    }

    #[Test]
    public function aLockedTenantDefinitionWithoutTemplateVersionIsComparedWithTheCurrentTemplate(): void
    {
        $template   = $this->leave(null);
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('existsInScope')->willReturn(true);
        $repository->expects(self::never())->method('findVersion');
        $repository->method('findCurrent')->willReturn($template);
        $versioning = new WorkflowVersioning($repository, lockCloneStructure: true);

        $own = $this->leave(new WorkflowTenant('org-a'));
        $versioning->assertStructureAllowed($own);
        $own->addTransition(new WorkflowTransition('reject', ['requested'], ['approved']));

        $this->expectException(WorkflowStructureLockedException::class);
        $this->expectExceptionMessage('its places and transitions must match the global template');
        $versioning->assertStructureAllowed($own);
    }

    private function leave(?WorkflowTenant $tenant): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'requested', 'X');
        if ($tenant instanceof WorkflowTenant) {
            $definition->startAsDraft($tenant)->publish();
        }
        $definition->addPlace(new WorkflowPlace('requested', null, 0));
        $definition->addPlace(new WorkflowPlace('approved', null, 1));
        $definition->addTransition(new WorkflowTransition('approve', ['requested'], ['approved']));

        return $definition;
    }
}
