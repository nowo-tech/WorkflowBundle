<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Resolves Symfony Workflow instances from persisted definitions.
 */
interface WorkflowRegistryInterface
{
    /**
     * Returns the Symfony workflow built from the enabled definition with the given slug.
     */
    public function get(string $workflowSlug): WorkflowInterface;
}
