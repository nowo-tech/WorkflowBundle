<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * How a transition apply is protected against a concurrent apply on the same subject (since 1.20, REQ-RUNTIME-003),
 * per workflow through `nowo_workflow.runtime.concurrency`. See docs/CONCURRENCY.md.
 */
enum WorkflowConcurrencyMode: string
{
    /**
     * Default. Expected-marking compare-and-set under a row lock: inside one transaction the subject's marking column
     * is re-read with `SELECT … FOR UPDATE` and must equal the marking the request loaded, then the transition is
     * applied and flushed. A changed marking refuses the apply with WorkflowConflictException; nothing is written.
     */
    case Lock = 'lock';

    /**
     * Optimistic: the subject entity must have a Doctrine `#[Version]` field. The marking is compared without a lock
     * and the versioned UPDATE decides at the flush; a lost race is a WorkflowConflictException.
     */
    case Optimistic = 'optimistic';

    /** No control (the 1.19 behaviour): two concurrent applies may both commit. */
    case None = 'none';
}
