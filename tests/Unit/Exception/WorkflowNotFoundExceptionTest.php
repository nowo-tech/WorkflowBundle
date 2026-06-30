<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Exception;

use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use PHPUnit\Framework\TestCase;

final class WorkflowNotFoundExceptionTest extends TestCase
{
    public function testForSlug(): void
    {
        $exception = WorkflowNotFoundException::forSlug('missing');

        self::assertStringContainsString('missing', $exception->getMessage());
    }

    public function testForContext(): void
    {
        $exception = WorkflowNotFoundException::forContext(new WorkflowContext('App\\Entity\\X', ['a' => '1']));

        self::assertStringContainsString('App\\Entity\\X', $exception->getMessage());
        self::assertStringContainsString('"a":"1"', $exception->getMessage());
    }
}
