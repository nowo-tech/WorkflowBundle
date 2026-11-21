<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Removed\Discovered\Versioning;

use Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * A 1.x host decorator of the removed versioning port (an audit log), registered by resource discovery with
 * `#[AsDecorator]` (audit XR-WF-M1). It cannot be loaded: the build must fail. Excluded from static analysis
 * (phpstan.neon.dist, rector.php); never instantiated.
 */
#[AsDecorator(WorkflowVersioningInterface::class)]
abstract class AuditingVersioningDecorator implements WorkflowVersioningInterface
{
}
