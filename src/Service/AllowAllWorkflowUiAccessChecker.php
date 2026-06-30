<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default UI access policy: allow every request (backward compatible).
 */
final class AllowAllWorkflowUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    public function isGranted(Request $request): bool
    {
        return true;
    }
}
