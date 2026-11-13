<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

use function sprintf;

/**
 * Builds Symfony Workflow Definition objects from persisted entities, the way FrameworkBundle builds them from
 * `framework.workflows` configuration.
 */
final class WorkflowDefinitionBuilder
{
    /**
     * Builds the Symfony workflow Definition (places, transitions, initial place, metadata) of a definition entity.
     */
    public function build(WorkflowDefinition $definition): Definition
    {
        $places = $definition->getPlaceNames();

        if ($places === []) {
            throw new InvalidArgumentException(sprintf('Workflow "%s" has no places defined.', $definition->getSlug()));
        }

        // Same semantics as FrameworkBundle's `framework.workflows` (since 1.15.2): a `workflow` transition keeps all its
        // places (it needs every `from` place marked, AND); a `state_machine` transition becomes one Transition per `from`
        // place with the same name, so a subject in any of its `from` places can take it (OR). A single Transition with
        // several `from` places never fired on a state machine (one place is marked at a time).
        $stateMachine = $definition->getType() === WorkflowType::StateMachine;
        $transitions  = [];
        foreach ($definition->getTransitions() as $transition) {
            $from = $transition->getFromPlaces();
            $to   = $transition->getToPlaces();
            $this->refuseShape($definition, $transition->getName(), WorkflowShapeValidator::transitionViolation($definition->getType(), $from, $to));
            if (!$stateMachine) {
                $transitions[] = new Transition($transition->getName(), $from, $to);

                continue;
            }
            foreach ($from as $place) {
                $transitions[] = new Transition($transition->getName(), [$place], $to);
            }
        }

        return new Definition(
            $places,
            $transitions,
            $definition->getInitialPlace(),
            new DatabaseMetadataStore($definition),
        );
    }

    /**
     * Since 1.16.0 (owner decision, audit OD M1): a stored transition FrameworkBundle would refuse (no `from` / `to`
     * place, several `to` places on a state machine) stops the build, as `framework.workflows` does at compile time.
     * 1.15.2 only warned; `nowo:workflow:lint` lists them.
     *
     * @throws InvalidArgumentException
     */
    private function refuseShape(WorkflowDefinition $definition, string $transition, ?string $violation): void
    {
        if ($violation !== null) {
            throw new InvalidArgumentException(sprintf('Workflow "%s", transition "%s": %s Fix it (see nowo:workflow:lint).', $definition->getSlug(), $transition, $violation));
        }
    }
}
