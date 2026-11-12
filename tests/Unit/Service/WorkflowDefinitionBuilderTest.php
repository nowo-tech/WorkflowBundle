<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Tests\Support\GuardProbeSubject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

use function array_map;

final class WorkflowDefinitionBuilderTest extends TestCase
{
    public function testBuildsDefinitionFromEntity(): void
    {
        $entity = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $entity->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $entity->addPlace(new WorkflowPlace('done', 'Done', 1));
        $entity->addTransition(new WorkflowTransition('finish', ['draft'], ['done'], 'Finish'));

        $definition = (new WorkflowDefinitionBuilder())->build($entity);

        self::assertSame(['draft', 'done'], array_values($definition->getPlaces()));
        self::assertCount(1, $definition->getTransitions());
        self::assertSame('finish', $definition->getTransitions()[0]->getName());
    }

    public function testThrowsWhenNoPlaces(): void
    {
        $entity = new WorkflowDefinition('Empty', 'empty', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);

        $this->expectException(InvalidArgumentException::class);
        (new WorkflowDefinitionBuilder())->build($entity);
    }

    /**
     * 1.15.2: like FrameworkBundle, a state machine transition becomes one Transition per (from, to) pair.
     */
    public function testStateMachineTransitionIsSplitPerFromAndToPlaceLikeFrameworkBundle(): void
    {
        $entity = $this->cancellable(WorkflowType::StateMachine);
        $entity->addTransition(new WorkflowTransition('fork', ['review'], ['done', 'cancelled']));

        $transitions = (new WorkflowDefinitionBuilder())->build($entity)->getTransitions();

        $pairs = array_map(static fn (Transition $transition): array => [
            $transition->getName(),
            $transition->getFroms(),
            $transition->getTos(),
        ], $transitions);
        self::assertSame([
            ['submit', ['draft'], ['review']],
            ['finish', ['review'], ['done']],
            ['cancel', ['draft'], ['cancelled']],
            ['cancel', ['review'], ['cancelled']],
            ['fork', ['review'], ['done']],
            ['fork', ['review'], ['cancelled']],
        ], $pairs);
    }

    /**
     * 1.15.2: a `workflow` transition keeps all its places (every `from` place must be marked), as in FrameworkBundle.
     */
    public function testWorkflowTransitionKeepsAllItsPlaces(): void
    {
        $transitions = (new WorkflowDefinitionBuilder())->build($this->cancellable(WorkflowType::Workflow))->getTransitions();

        self::assertCount(3, $transitions);
        self::assertSame('cancel', $transitions[2]->getName());
        self::assertSame(['draft', 'review'], $transitions[2]->getFroms());
        self::assertSame(['cancelled'], $transitions[2]->getTos());
    }

    /**
     * 1.15.2 (Beacon): a state machine transition with several `from` places fires from each of them; it never fired.
     */
    public function testStateMachineTransitionWithSeveralFromPlacesFiresFromEachOfThem(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($this->cancellable(WorkflowType::StateMachine));
        $workflow = (new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()))->get('order');

        foreach (['draft', 'review'] as $place) {
            $subject = new GuardProbeSubject($place);
            self::assertTrue($workflow->can($subject, 'cancel'), $place);
            $workflow->apply($subject, 'cancel');
            self::assertSame('cancelled', $subject->getStatus());
        }
        self::assertFalse($workflow->can(new GuardProbeSubject('done'), 'cancel'));
        self::assertSame(['submit', 'cancel'], array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $workflow->getEnabledTransitions(new GuardProbeSubject('draft')),
        ));
    }

    private function cancellable(WorkflowType $type): WorkflowDefinition
    {
        $entity = new WorkflowDefinition('Order', 'order', 'draft', GuardProbeSubject::class, $type);
        foreach (['draft', 'review', 'done', 'cancelled'] as $index => $place) {
            $entity->addPlace(new WorkflowPlace($place, null, $index));
        }
        $entity->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        $entity->addTransition(new WorkflowTransition('finish', ['review'], ['done']));
        $entity->addTransition(new WorkflowTransition('cancel', ['draft', 'review'], ['cancelled']));

        return $entity;
    }
}
