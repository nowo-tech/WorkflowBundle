<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Model\WorkflowPinResult;
use Nowo\WorkflowBundle\Model\WorkflowTransitionResult;

use function array_values;

/**
 * Default {@see WorkflowRuntimeInterface} (since 1.15): {@see WorkflowApplicator} behind a framework-free surface.
 * The applicator keeps asking {@see WorkflowRegistryInterface} (without tenancy), so a host registry or a decorator
 * of it keeps running.
 */
final readonly class WorkflowRuntime implements WorkflowRuntimeInterface
{
    public function __construct(
        private WorkflowApplicator $applicator,
    ) {
    }

    public function can(object $subject, string $workflowSlug, string $transition): bool
    {
        return $this->applicator->can($subject, $workflowSlug, $transition);
    }

    public function apply(object $subject, string $workflowSlug, string $transition): WorkflowTransitionResult
    {
        try {
            $automatic = $this->applicator->applyWithAutomatic($subject, $workflowSlug, $transition);
        } catch (WorkflowTransitionBlockedException $exception) {
            return new WorkflowTransitionResult(false, $transition, $this->marking($subject, $workflowSlug), [], array_values($exception->getBlockerCodes()), array_values($exception->getBlockerMessages()));
        }

        return new WorkflowTransitionResult(true, $transition, $this->marking($subject, $workflowSlug), $automatic);
    }

    public function enabledTransitions(object $subject, string $workflowSlug): array
    {
        return $this->applicator->getEnabledTransitions($subject, $workflowSlug);
    }

    public function marking(object $subject, string $workflowSlug): WorkflowMarkingView
    {
        return $this->applicator->markingView($subject, $workflowSlug);
    }

    public function pin(object $subject, string $workflowSlug, WorkflowPinMode $mode = WorkflowPinMode::KeepExisting): WorkflowPinResult
    {
        return $this->applicator->pin($subject, $workflowSlug, $mode);
    }
}
