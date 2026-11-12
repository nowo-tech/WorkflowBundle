<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use InvalidArgumentException;
use LogicException;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Model\WorkflowPinResult;
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

    /**
     * Pins the subject to a definition version without applying any transition (since 1.16): no initial marking is
     * written, no workflow event is dispatched, no automatic transition runs and nothing is flushed (the pin is a plain
     * DBAL insert / update on the definitions' connection, part of your transaction when one is open).
     *
     * The version is the one the subject would start with now: its tenant's current published version, else the
     * global template's (never another tenant's, never a draft or a retired clone). Idempotent: a subject that is
     * already pinned keeps its pin (`WorkflowPinMode::KeepExisting`, the default) unless `WorkflowPinMode::Repin` is
     * passed, which moves it to that version when its current places exist there. An existing pin that does not belong
     * to the subject's tenant is refused, as in every other call.
     *
     * @throws WorkflowNotFoundException when no enabled version applies, or the existing pin is not valid for the subject
     * @throws InvalidArgumentException when the subject is not supported, has no subject reference, or (Repin) its
     *                                  places do not exist in the new version
     * @throws LogicException when tenancy (`nowo_workflow.tenancy.tenant_resolver`) is off
     */
    public function pin(object $subject, string $workflowSlug, WorkflowPinMode $mode = WorkflowPinMode::KeepExisting): WorkflowPinResult;
}
