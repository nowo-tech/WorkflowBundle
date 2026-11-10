<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

/**
 * Subject with a method marking store property for WorkflowEventsKernel.
 */
final class GuardProbeSubject
{
    public function __construct(private string $status = 'draft')
    {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
