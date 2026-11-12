<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use LogicException;
use Throwable;

use function sprintf;

/**
 * A published definition cannot be deleted while subjects are pinned to one of its versions (since 1.15, owner
 * decision 2026-11-12): disable it instead, or remove those subjects' pins (`WorkflowVersioning::forgetSubject()`).
 */
final class WorkflowDefinitionInUseException extends LogicException
{
    public function __construct(
        public readonly string $workflowSlug,
        public readonly int $pinnedSubjects,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Workflow "%s" cannot be deleted: %d running subject(s) are pinned to its versions. Disable it instead.', $workflowSlug, $pinnedSubjects), 0, $previous);
    }
}
