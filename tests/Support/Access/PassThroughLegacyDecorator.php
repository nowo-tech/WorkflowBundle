<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

/** A host decorator of the deprecated port (logging, auditing…) that keeps the inner answer and counts its calls. */
final class PassThroughLegacyDecorator implements WorkflowUiAccessCheckerInterface
{
    public int $calls = 0;

    public function __construct(private readonly WorkflowUiAccessCheckerInterface $inner)
    {
    }

    public function isGranted(Request $request): bool
    {
        ++$this->calls;

        return $this->inner->isGranted($request);
    }
}
