<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Model\WorkflowTransitionResult;

/**
 * Framework-free runtime port of the database workflows (since 1.15, REQ-ARCH-001 rule 3): what a host calls to move
 * its subjects, with scalars and bundle VOs only (no Symfony Workflow type). It replaces
 * {@see WorkflowRegistryInterface} as the port hosts call.
 *
 * The subject is the host's own object. With tenancy the definition is the one the subject runs (its pinned version,
 * else its tenant's, else the global template, never another tenant's). Guards (permission, conditions, host
 * `workflow.guard` listeners) and automatic transitions apply exactly as with `WorkflowApplicator`.
 */
interface WorkflowRuntimeInterface
{
    /**
     * Whether the transition can be applied now (guards evaluated).
     *
     * @throws WorkflowNotFoundException
     */
    public function can(object $subject, string $workflowSlug, string $transition): bool;

    /**
     * Applies the transition (and the automatic transitions it enables), then flushes unless
     * `runtime.flush_after_apply` is false. A blocked transition is not an error: the result says why.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidArgumentException when the subject is not supported or the transition is not defined
     */
    public function apply(object $subject, string $workflowSlug, string $transition): WorkflowTransitionResult;

    /**
     * The transitions the subject can take now, by name.
     *
     * @throws WorkflowNotFoundException
     *
     * @return list<string>
     */
    public function enabledTransitions(object $subject, string $workflowSlug): array;

    /**
     * The subject's current marking.
     *
     * @throws WorkflowNotFoundException
     */
    public function marking(object $subject, string $workflowSlug): WorkflowMarkingView;
}
