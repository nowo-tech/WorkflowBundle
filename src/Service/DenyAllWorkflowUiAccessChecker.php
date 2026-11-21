<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/**
 * Fail-closed UI access policy: deny every request.
 *
 * Used as the default alias and when the bundle is disabled without a usable security setup.
 */
final class DenyAllWorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface
{
    /**
     * Denies every request (fail-closed default).
     */
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return false;
    }
}
