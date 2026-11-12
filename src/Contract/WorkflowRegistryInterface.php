<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Resolves Symfony Workflow instances from persisted definitions.
 *
 * @deprecated since 1.15 as a port for hosts to call or replace (REQ-ARCH-001 rule 3: it returns a Symfony type), use
 *             {@see WorkflowRuntimeInterface}; removed in 2.0. Until then it stays the SPI `WorkflowApplicator` asks
 *             without tenancy, so a host implementation or decorator keeps running (with a build-time deprecation).
 */
interface WorkflowRegistryInterface
{
    /**
     * Returns the Symfony workflow built from the enabled definition with the given slug.
     */
    public function get(string $workflowSlug): WorkflowInterface;
}
