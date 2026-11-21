<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Removed\Discovered\Registry;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A 1.x host registry aliased to the removed registry port with `#[AsAlias]`, registered by resource discovery (audit
 * XR-WF-M1). It cannot be loaded: the build must fail. Excluded from static analysis (phpstan.neon.dist, rector.php).
 */
#[AsAlias(WorkflowRegistryInterface::class)]
final class HostWorkflowRegistry implements WorkflowRegistryInterface
{
    public function get(string $workflowSlug): WorkflowInterface
    {
        throw new LogicException('never built');
    }
}
