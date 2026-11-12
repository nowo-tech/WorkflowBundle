<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

/** A configured access checker that implements neither port (the build must fail). */
final class NotAPort
{
    public function isGranted(): bool
    {
        return true;
    }
}
