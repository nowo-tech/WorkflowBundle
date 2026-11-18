<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Concurrency;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Concurrency\WorkflowConcurrencyPolicy;
use Nowo\WorkflowBundle\Enum\WorkflowConcurrencyMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowConcurrencyPolicyTest extends TestCase
{
    #[Test]
    public function theDefaultIsTheLockMode(): void
    {
        $policy = new WorkflowConcurrencyPolicy();

        self::assertSame(WorkflowConcurrencyMode::Lock, $policy->modeFor('anything'));
        self::assertSame(1, $policy->retryAfterSeconds);
    }

    #[Test]
    public function eachWorkflowCanHaveItsOwnMode(): void
    {
        $policy = new WorkflowConcurrencyPolicy('optimistic', ['leave' => 'none', 'order' => WorkflowConcurrencyMode::Lock], 3);

        self::assertSame(WorkflowConcurrencyMode::None, $policy->modeFor('leave'));
        self::assertSame(WorkflowConcurrencyMode::Lock, $policy->modeFor('order'));
        self::assertSame(WorkflowConcurrencyMode::Optimistic, $policy->modeFor('other'));
        self::assertSame(3, $policy->retryAfterSeconds);
    }

    #[Test]
    public function anUnknownModeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown workflow concurrency mode "pessimistic"');

        new WorkflowConcurrencyPolicy('lock', ['leave' => 'pessimistic']);
    }

    #[Test]
    public function aNegativeRetryAfterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkflowConcurrencyPolicy(retryAfterSeconds: -1);
    }
}
