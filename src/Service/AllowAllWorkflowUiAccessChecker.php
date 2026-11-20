<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/**
 * Default UI access policy: allow every request (backward compatible).
 */
final class AllowAllWorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface
{
    /**
     * Grants every request (allow_unauthenticated: dev / demo only).
     */
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return true;
    }
}
