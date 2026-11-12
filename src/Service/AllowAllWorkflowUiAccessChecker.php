<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default UI access policy: allow every request (backward compatible).
 */
final class AllowAllWorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface, WorkflowUiAccessCheckerInterface
{
    /**
     * Grants every request (allow_unauthenticated: dev / demo only).
     */
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return true;
    }

    /**
     * Deprecated port (removed in 2.0): same answer as {@see allows()}.
     */
    public function isGranted(Request $request): bool
    {
        return true;
    }
}
