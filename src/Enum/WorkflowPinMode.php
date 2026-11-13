<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;

/**
 * What {@see WorkflowRuntimeInterface::pin()} does with a subject that is already
 * pinned (since 1.16).
 */
enum WorkflowPinMode: string
{
    /** Keep the existing pin (idempotent): a subject already pinned stays on its version. */
    case KeepExisting = 'keep_existing';

    /**
     * Move the subject to the version a new subject of its tenant starts with now (the tenant's current published
     * version, else the global template). Refused when the subject's current places do not exist in that version.
     */
    case Repin = 'repin';
}
