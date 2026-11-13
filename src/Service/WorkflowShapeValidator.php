<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;

use function array_unique;
use function count;
use function sprintf;

/**
 * Shape rules of stored transitions (since 1.15.2), as FrameworkBundle enforces them for `framework.workflows`:
 *
 * - every transition has at least one `from` and one `to` place, each listed once;
 * - a `state_machine` transition has exactly one `to` place (StateMachineValidator: one name from one place).
 *
 * Checked by the editor (on the submitted type), by `WorkflowVersioning::publish()` and by `nowo:workflow:lint`. The
 * builder only warns in 1.15.x (deprecation) and keeps the 1.15.1 behaviour for such a stored transition.
 */
final class WorkflowShapeValidator
{
    /**
     * The violations of a definition, one message per transition ([] = valid).
     *
     * @return list<string>
     */
    public static function violations(WorkflowDefinition $definition): array
    {
        $violations = [];
        foreach ($definition->getTransitions() as $transition) {
            $message = self::transitionViolation($definition->getType(), $transition->getFromPlaces(), $transition->getToPlaces());
            if ($message !== null) {
                $violations[] = sprintf('Transition "%s": %s', $transition->getName(), $message);
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     */
    public static function transitionViolation(WorkflowType $type, array $from, array $to): ?string
    {
        if ($from === [] || $to === []) {
            return 'it needs at least one source place and one target place.';
        }
        if (count(array_unique($from)) !== count($from) || count(array_unique($to)) !== count($to)) {
            return 'a place is listed twice in its source or target places.';
        }
        if ($type === WorkflowType::StateMachine && count($to) !== 1) {
            return 'a state machine transition has exactly one target place.';
        }

        return null;
    }
}
