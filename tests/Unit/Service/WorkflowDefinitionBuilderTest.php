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
use PHPUnit\Framework\Attributes\DataProvider;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

use function array_map;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

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
     * 1.15.2: like FrameworkBundle, a state machine transition becomes one Transition per `from` place (same name).
     */
    public function testStateMachineTransitionIsSplitPerFromPlaceLikeFrameworkBundle(): void
    {
        $transitions = (new WorkflowDefinitionBuilder())->build($this->cancellable(WorkflowType::StateMachine))->getTransitions();

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
        ], $pairs);
    }

    /**
     * 1.15.2 (owner decision): a stored state machine transition FrameworkBundle would refuse still runs as in 1.15.1
     * (several targets: the subject lands on the first one; no source: one transition without source), with a
     * deprecation and a warning naming the workflow and the transition. 1.16.0 refuses it.
     *
     * @return iterable<string, array{list<string>, list<string>, list<array{list<string>, list<string>}>}>
     */
    public static function invalidStateMachineTransitions(): iterable
    {
        yield 'two targets' => [['draft', 'review'], ['done', 'cancelled'], [[['draft'], ['done', 'cancelled']], [['review'], ['done', 'cancelled']]]];
        yield 'no target' => [['review'], [], [[['review'], []]]];
        yield 'no source' => [[], ['done'], [[[], ['done']]]];
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     * @param list<array{list<string>, list<string>}> $expected
     */
    #[DataProvider('invalidStateMachineTransitions')]
    public function testInvalidStateMachineTransitionKeepsThe1151BehaviourWithADeprecation(array $from, array $to, array $expected): void
    {
        $entity = $this->cancellable(WorkflowType::StateMachine);
        $entity->addTransition(new WorkflowTransition('fork', $from, $to));
        $logger       = new RecordingLogger();
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $transitions = (new WorkflowDefinitionBuilder($logger))->build($entity)->getTransitions();
        } finally {
            restore_error_handler();
        }

        $fork = [];
        foreach ($transitions as $transition) {
            if ($transition->getName() === 'fork') {
                $fork[] = [$transition->getFroms(), $transition->getTos()];
            }
        }
        self::assertSame($expected, $fork);
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('Workflow "order", transition "fork"', $deprecations[0]);
        self::assertStringContainsString('1.16.0 refuses to build it', $deprecations[0]);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testSeveralTargetsOnAStateMachineStillLandOnTheFirstOne(): void
    {
        $entity = $this->cancellable(WorkflowType::StateMachine);
        $entity->addTransition(new WorkflowTransition('fork', ['review'], ['done', 'cancelled']));
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($entity);
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);

        try {
            $workflow = (new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()))->get('order');
            $subject  = new GuardProbeSubject('review');
            $workflow->apply($subject, 'fork');
        } finally {
            restore_error_handler();
        }

        self::assertSame('done', $subject->getStatus());
    }

    public function testWorkflowTransitionMayHaveSeveralTargets(): void
    {
        $entity = $this->cancellable(WorkflowType::Workflow);
        $entity->addTransition(new WorkflowTransition('fork', ['review'], ['done', 'cancelled']));

        $transitions = (new WorkflowDefinitionBuilder())->build($entity)->getTransitions();
        self::assertSame(['done', 'cancelled'], $transitions[3]->getTos());
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
