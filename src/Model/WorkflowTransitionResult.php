<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;

/**
 * Outcome of {@see WorkflowRuntimeInterface::apply()} (since 1.15).
 *
 * Applied: `marking` is the marking after the transition and the automatic transitions it enabled
 * (`automaticTransitions`, in order). Blocked: nothing changed, `marking` is the current one and `blockerCodes` /
 * `blockerMessages` say why (the guard codes, e.g. `nowo_workflow.permission_denied`).
 */
final readonly class WorkflowTransitionResult
{
    /**
     * @param list<string> $automaticTransitions
     * @param list<string> $blockerCodes
     * @param list<string> $blockerMessages
     */
    public function __construct(
        public bool $applied,
        public string $transition,
        public WorkflowMarkingView $marking,
        public array $automaticTransitions = [],
        public array $blockerCodes = [],
        public array $blockerMessages = [],
    ) {
    }
}
