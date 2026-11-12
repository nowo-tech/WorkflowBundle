<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowActorRef;

/**
 * Host port (since 1.15): who is applying transitions right now (`nowo_workflow.tenancy.actor_provider`).
 *
 * Without it the bundle uses the identifier of the Symfony security token's user when SecurityBundle is installed,
 * else no actor. Resolved per call, never cached.
 */
interface WorkflowActorProviderInterface
{
    public function currentActor(): ?WorkflowActorRef;
}
