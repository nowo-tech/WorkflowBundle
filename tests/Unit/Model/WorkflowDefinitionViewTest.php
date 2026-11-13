<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowDefinitionStatus;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.19 (REQ-ARCH-001 rule 1): the value objects of the framework-free versioning twin. No Doctrine `Collection`
 * crosses them: places and transitions are name lists.
 */
final class WorkflowDefinitionViewTest extends TestCase
{
    #[Test]
    public function theViewOfADefinitionCarriesItsScopeAndItsNames(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $definition = $this->leave($tenant, 7, 3);
        $definition->setDescription('Leave requests')->setPriority(5)->setMetadata(['colour' => 'blue'])->setMarkingProperty('state');

        $view = WorkflowDefinitionView::fromDefinition($definition);

        self::assertSame(7, $view->ref->id);
        self::assertSame('leave', $view->ref->slug);
        self::assertSame(3, $view->ref->version);
        self::assertEquals($tenant, $view->ref->tenant);
        self::assertSame('Leave approval', $view->name);
        self::assertSame(WorkflowType::StateMachine, $view->type);
        self::assertSame(WorkflowDefinitionStatus::Published, $view->status);
        self::assertSame('requested', $view->initialPlace);
        self::assertSame('App\\Entity\\LeaveRequest', $view->subjectClass);
        self::assertSame('state', $view->markingProperty);
        self::assertTrue($view->enabled);
        self::assertSame('Leave requests', $view->description);
        self::assertSame(5, $view->priority);
        self::assertNull($view->templateVersion);
        self::assertSame(['requested', 'approved'], $view->placeNames);
        self::assertSame(['approve'], $view->transitionNames);
        self::assertSame(['colour' => 'blue'], $view->metadata);
        self::assertSame($definition->getCreatedAt(), $view->createdAt);
        self::assertSame($definition->getUpdatedAt(), $view->updatedAt);
    }

    /**
     * @return iterable<string, array{WorkflowDefinitionStatus, bool, bool, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'draft' => [WorkflowDefinitionStatus::Draft, true, false, false];
        yield 'published' => [WorkflowDefinitionStatus::Published, false, true, false];
        yield 'retired' => [WorkflowDefinitionStatus::Retired, false, false, true];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function theViewAnswersItsStatus(WorkflowDefinitionStatus $status, bool $draft, bool $published, bool $retired): void
    {
        $definition = $this->leave(new WorkflowTenant('org-a'), 7, 1);
        (new ReflectionProperty(WorkflowDefinition::class, 'status'))->setValue($definition, $status);
        $view = WorkflowDefinitionView::fromDefinition($definition);

        self::assertSame($draft, $view->isDraft());
        self::assertSame($published, $view->isPublished());
        self::assertSame($retired, $view->isRetired());
    }

    #[Test]
    public function aGlobalTemplateHasNoTenant(): void
    {
        $reference = WorkflowDefinitionRef::fromDefinition($this->leave(null, 4, 1));

        self::assertSame(4, $reference->id);
        self::assertNull($reference->tenant);
        self::assertSame(1, $reference->version);
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'no id (never saved)' => [0, 'leave', 1];
        yield 'empty slug' => [1, '', 1];
        yield 'no version' => [1, 'leave', 0];
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function aReferenceNeedsASlugAnIdAndAVersion(int $id, string $slug, int $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A workflow definition reference needs a non-empty slug and a positive id and version');
        new WorkflowDefinitionRef($id, $slug, null, $version);
    }

    /**
     * A stored "leave" definition with the given row id and version number.
     */
    private function leave(?WorkflowTenant $tenant, int $id, int $version): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave approval', 'leave', 'requested', 'App\\Entity\\LeaveRequest');
        $definition->addPlace(new WorkflowPlace('requested', null, 0));
        $definition->addPlace(new WorkflowPlace('approved', null, 1));
        $definition->addTransition(new WorkflowTransition('approve', ['requested'], ['approved']));
        if ($tenant instanceof WorkflowTenant) {
            $definition->startAsDraft($tenant)->publish();
        }
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, $id);
        (new ReflectionProperty(WorkflowDefinition::class, 'version'))->setValue($definition, $version);

        return $definition;
    }
}
