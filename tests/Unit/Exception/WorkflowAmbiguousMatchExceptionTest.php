<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Exception;

use Nowo\WorkflowBundle\Exception\WorkflowAmbiguousMatchException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use PHPUnit\Framework\TestCase;

final class WorkflowAmbiguousMatchExceptionTest extends TestCase
{
    public function testForContextIncludesSlugs(): void
    {
        $exception = WorkflowAmbiguousMatchException::forContext(
            new WorkflowContext('App\\Entity\\X', ['tenant' => 'acme']),
            ['slug_a', 'slug_b'],
        );

        self::assertStringContainsString('slug_a', $exception->getMessage());
        self::assertStringContainsString('slug_b', $exception->getMessage());
    }
}
