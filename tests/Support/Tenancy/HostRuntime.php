<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Model\WorkflowPinResult;
use Nowo\WorkflowBundle\Model\WorkflowTransitionResult;

/**
 * A host's own runtime port (it wins over the built-in one): refuses everything.
 */
final class HostRuntime implements WorkflowRuntimeInterface
{
    public function can(object $subject, string $workflowSlug, string $transition): bool
    {
        return false;
    }

    public function apply(object $subject, string $workflowSlug, string $transition): WorkflowTransitionResult
    {
        return new WorkflowTransitionResult(false, $transition, $this->marking($subject, $workflowSlug), [], ['host.refused'], ['Refused by the host.']);
    }

    public function enabledTransitions(object $subject, string $workflowSlug): array
    {
        return [];
    }

    public function marking(object $subject, string $workflowSlug): WorkflowMarkingView
    {
        return new WorkflowMarkingView($workflowSlug, 0, null, []);
    }

    public function pin(object $subject, string $workflowSlug, WorkflowPinMode $mode = WorkflowPinMode::KeepExisting): WorkflowPinResult
    {
        throw new LogicException('The host runtime does not pin.');
    }
}
