<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowActor;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/** The same host rule on the 1.14 port: it reads only the VO. */
final readonly class PolicyPredicate implements WorkflowUiAccessPolicyInterface
{
    public function __construct(private string $predicate)
    {
    }

    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return AccessPredicates::decide(
            $this->predicate,
            $check->method,
            $check->routeName,
            $check->path(),
            $check->routeParams(),
            $check->actor?->userIdentifier,
            $check->actor instanceof WorkflowActor ? $check->actor->roles : [],
            $check->actor?->impersonatorIdentifier,
        );
    }
}
