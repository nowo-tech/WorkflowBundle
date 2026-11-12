<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\HttpFoundation\Request;

/**
 * A host service on both ports. Its deprecated method throws, so a call through the deprecated port by mistake shows
 * up as a difference in the matrix.
 */
final readonly class DualPredicate implements WorkflowUiAccessPolicyInterface, WorkflowUiAccessCheckerInterface
{
    public function __construct(private string $predicate)
    {
    }

    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return (new PolicyPredicate($this->predicate))->allows($check);
    }

    public function isGranted(Request $request): bool
    {
        throw new LogicException('The deprecated port of a dual service must not be asked.');
    }
}
