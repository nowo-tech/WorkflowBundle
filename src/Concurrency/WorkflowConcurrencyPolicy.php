<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Concurrency;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Enum\WorkflowConcurrencyMode;

use function is_string;
use function sprintf;

/**
 * The concurrency control of each workflow (since 1.20, REQ-RUNTIME-003): `nowo_workflow.runtime.concurrency`.
 * Immutable; safe in a long-running worker.
 */
final readonly class WorkflowConcurrencyPolicy
{
    private WorkflowConcurrencyMode $default;

    /** @var array<string, WorkflowConcurrencyMode> */
    private array $workflows;

    /**
     * @param string|WorkflowConcurrencyMode $default Mode of every workflow not listed
     * @param array<string, string|WorkflowConcurrencyMode> $workflows Mode per workflow slug
     * @param int $retryAfterSeconds Retry-After of a conflict, in seconds
     *
     * @throws InvalidArgumentException on an unknown mode or a negative Retry-After
     */
    public function __construct(
        string|WorkflowConcurrencyMode $default = WorkflowConcurrencyMode::Lock,
        array $workflows = [],
        public int $retryAfterSeconds = 1,
    ) {
        if ($retryAfterSeconds < 0) {
            throw new InvalidArgumentException('nowo_workflow.runtime.concurrency.retry_after must be zero or more.');
        }
        $this->default = $this->mode($default);
        $modes         = [];
        foreach ($workflows as $slug => $mode) {
            $modes[$slug] = $this->mode($mode);
        }
        $this->workflows = $modes;
    }

    public function modeFor(string $workflowSlug): WorkflowConcurrencyMode
    {
        return $this->workflows[$workflowSlug] ?? $this->default;
    }

    private function mode(string|WorkflowConcurrencyMode $mode): WorkflowConcurrencyMode
    {
        if (!is_string($mode)) {
            return $mode;
        }

        return WorkflowConcurrencyMode::tryFrom($mode) ?? throw new InvalidArgumentException(sprintf('Unknown workflow concurrency mode "%s" (expected lock, optimistic or none).', $mode));
    }
}
