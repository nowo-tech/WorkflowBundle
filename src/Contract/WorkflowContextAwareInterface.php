<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowContext;

/**
 * Domain subjects that expose parameters for automatic workflow resolution.
 */
interface WorkflowContextAwareInterface
{
    public function getWorkflowContext(): WorkflowContext;
}
