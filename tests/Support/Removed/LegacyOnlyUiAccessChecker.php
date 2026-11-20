<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Removed;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A 1.x host access checker that still implements only the request-based port removed in 2.0. The interface no longer
 * exists, so this class cannot be loaded: the build must fail with a clear message, never with a PHP fatal error.
 * Excluded from static analysis (phpstan.neon.dist, rector.php) for that reason.
 */
final class LegacyOnlyUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    public function isGranted(Request $request): bool
    {
        return true;
    }
}
