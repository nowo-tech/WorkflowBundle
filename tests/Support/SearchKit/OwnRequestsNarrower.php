<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Closure;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;

/**
 * The host's narrowing in the tests: a closure decides the conditions; records each call.
 */
final class OwnRequestsNarrower implements WorkflowRequestSearchNarrowerInterface
{
    /** @var list<array{0: string, 1: string, 2: int|string}> */
    public array $calls = [];

    /** @param Closure(WorkflowRequestSearchCriteria, string): mixed $rule */
    public function __construct(private readonly Closure $rule)
    {
    }

    public function narrow(string $subject, WorkflowRequestSearchCriteria $criteria, string $userIdentifier, int|string $tenant): void
    {
        $this->calls[] = [$subject, $userIdentifier, $tenant];
        ($this->rule)($criteria, $userIdentifier);
    }
}
