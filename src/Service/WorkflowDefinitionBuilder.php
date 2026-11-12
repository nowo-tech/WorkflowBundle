<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

use function count;
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
        // places (it needs every `from` place marked, AND); a `state_machine` transition becomes one Transition per
        // (`from`, `to`) pair with the same name, so a subject in any of its `from` places can take it (OR). A single
        // Transition with several `from` places never fired on a state machine (one place is marked at a time). A state
        // machine transition needs exactly one `to` place, as FrameworkBundle's validation requires.
        $stateMachine = $definition->getType() === WorkflowType::StateMachine;
        $transitions  = [];
        foreach ($definition->getTransitions() as $transition) {
            if (!$stateMachine) {
                $transitions[] = new Transition($transition->getName(), $transition->getFromPlaces(), $transition->getToPlaces());

                continue;
            }
            // FrameworkBundle refuses such a state machine when the container is compiled (StateMachineValidator: one
            // name from one place); refuse it here too instead of failing in the middle of apply().
            if ($transition->getFromPlaces() === [] || count($transition->getToPlaces()) !== 1) {
                throw new InvalidArgumentException(sprintf('Transition "%s" of state machine "%s" must have at least one source place and exactly one target place.', $transition->getName(), $definition->getSlug()));
            }
            foreach ($transition->getFromPlaces() as $from) {
                foreach ($transition->getToPlaces() as $to) {
                    $transitions[] = new Transition($transition->getName(), [$from], [$to]);
                }
            }
        }

        return new Definition(
            $places,
            $transitions,
            $definition->getInitialPlace(),
            new DatabaseMetadataStore($definition),
        );
    }
}
