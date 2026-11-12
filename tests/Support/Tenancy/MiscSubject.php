<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

/**
 * A subject the host's tenancy ports know nothing about: no tenant, no reference (global templates, never pinned).
 */
final class MiscSubject
{
    private string $status = 'a';

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
