<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed UI access policy: deny every request.
 *
 * Used as the default alias and when the bundle is disabled without a usable security setup.
 */
final class DenyAllWorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface, WorkflowUiAccessCheckerInterface
{
    /**
     * Denies every request (fail-closed default).
     */
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return false;
    }

    /**
     * Deprecated port (removed in 2.0): same answer as {@see allows()}.
     */
    public function isGranted(Request $request): bool
    {
        return false;
    }
}
