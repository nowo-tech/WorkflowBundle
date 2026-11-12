<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use InvalidArgumentException;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

use function sprintf;
use function trigger_deprecation;

/**
 * Builds Symfony Workflow Definition objects from persisted entities, the way FrameworkBundle builds them from
 * `framework.workflows` configuration.
 */
final class WorkflowDefinitionBuilder
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

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
            $this->warnAboutShape($definition, $transition->getName(), WorkflowShapeValidator::transitionViolation($definition->getType(), $from, $to));
            if (!$stateMachine || $from === []) {
                $transitions[] = new Transition($transition->getName(), $from, $to);

                continue;
            }
            foreach ($from as $place) {
                // Several `to` places keep the 1.15.1 behaviour (the subject lands on the first one) until 1.16.0.
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
     * 1.15.2 (owner decision): a stored transition with an invalid shape still runs as in 1.15.1, with a deprecation
     * and a warning; 1.16.0 refuses it. `nowo:workflow:lint` lists them.
     */
    private function warnAboutShape(WorkflowDefinition $definition, string $transition, ?string $violation): void
    {
        if ($violation === null) {
            return;
        }
        $message = sprintf('Workflow "%s", transition "%s": %s Fix it (see nowo:workflow:lint); 1.16.0 refuses to build it.', $definition->getSlug(), $transition, $violation);
        trigger_deprecation(Configuration::PACKAGE, '1.15.2', $message);
        $this->logger?->warning($message, ['bundle' => 'NowoWorkflowBundle', 'workflow' => $definition->getSlug(), 'transition' => $transition]);
    }
}
