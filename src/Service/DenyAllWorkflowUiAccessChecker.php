<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed UI access policy: deny every request.
 *
 * Used as the default alias and when the bundle is disabled without a usable security setup.
 */
final class DenyAllWorkflowUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    public function isGranted(Request $request): bool
    {
        return false;
    }
}
