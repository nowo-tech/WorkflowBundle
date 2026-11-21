<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Removed;

use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;

/**
 * A 1.x host runtime that also kept the registry port removed in 2.0. The interface no longer exists, so this class
 * cannot be loaded: the build must fail with a clear message, never with a PHP fatal error. Excluded from static
 * analysis (phpstan.neon.dist, rector.php) for that reason; never instantiated.
 */
abstract class LegacyRegistryRuntime implements WorkflowRuntimeInterface, WorkflowRegistryInterface
{
}
