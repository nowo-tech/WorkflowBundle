<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowTransitionPermissionCheck;

/**
 * Host port (since 1.15): the "permission" guard an editor can set on a transition
 * (`nowo_workflow.guards.permission_checker`).
 *
 * Decide in the subject's context (e.g. "is the actor a manager of the employee who asked for leave?"), not with
 * global roles. Without this port, a transition that has a permission guard is always blocked (fail closed).
 */
interface WorkflowTransitionPermissionCheckerInterface
{
    public function isGranted(WorkflowTransitionPermissionCheck $check): bool;
}
