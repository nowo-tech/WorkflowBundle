<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use LogicException;

use function implode;
use function sprintf;

/**
 * A definition has transitions FrameworkBundle would refuse (since 1.15.2, {@see \Nowo\WorkflowBundle\Service\WorkflowShapeValidator}):
 * a transition without source or target place, or a state machine transition with several target places.
 */
final class WorkflowInvalidShapeException extends LogicException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public readonly string $workflowSlug,
        public readonly array $violations,
    ) {
        parent::__construct(sprintf('Workflow "%s" has invalid transitions: %s', $workflowSlug, implode(' ', $violations)));
    }
}
