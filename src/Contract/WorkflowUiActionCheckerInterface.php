<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowUiActionCheck;

/**
 * Host port (since 1.15): may the actor view, edit or publish in this tenant scope
 * (`nowo_workflow.tenancy.ui.action_checker`)?
 *
 * Asked by the Workflow UI controllers on top of {@see WorkflowUiAccessPolicyInterface} (both must grant). Required
 * when tenancy and the UI are both enabled (the container build fails otherwise); optional without tenancy.
 */
interface WorkflowUiActionCheckerInterface
{
    public function allows(WorkflowUiActionCheck $check): bool;
}
