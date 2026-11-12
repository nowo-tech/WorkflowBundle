<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A 1.14 host decorator of the deprecated registry port: it must keep running behind the 1.15 runtime port.
 */
final class CountingRegistryDecorator implements WorkflowRegistryInterface
{
    public int $calls = 0;

    public function __construct(private readonly WorkflowRegistryInterface $inner)
    {
    }

    public function get(string $workflowSlug): WorkflowInterface
    {
        ++$this->calls;

        return $this->inner->get($workflowSlug);
    }
}
