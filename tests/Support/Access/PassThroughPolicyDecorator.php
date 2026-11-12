<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/** A host decorator of the 1.14 port (logging, auditing…) that keeps the inner answer and counts its calls. */
final class PassThroughPolicyDecorator implements WorkflowUiAccessPolicyInterface
{
    public int $calls = 0;

    public function __construct(private readonly WorkflowUiAccessPolicyInterface $inner)
    {
    }

    public function allows(WorkflowUiAccessCheck $check): bool
    {
        ++$this->calls;

        return $this->inner->allows($check);
    }
}
