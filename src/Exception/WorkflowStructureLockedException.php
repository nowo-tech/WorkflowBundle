<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use LogicException;

use function sprintf;

/**
 * The places and transitions of a tenant clone are locked to its global template (since 1.16,
 * `nowo_workflow.tenancy.lock_clone_structure`): only guards and conditions may differ.
 */
final class WorkflowStructureLockedException extends LogicException
{
    public function __construct(
        public readonly string $workflowSlug,
        string $reason = 'its places and transitions must match the global template',
    ) {
        parent::__construct(sprintf('Workflow "%s" is locked to its template: %s.', $workflowSlug, $reason));
    }
}
