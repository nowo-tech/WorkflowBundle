<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Exception;

use Nowo\WorkflowBundle\Exception\WorkflowNameCollisionException;
use PHPUnit\Framework\TestCase;

final class WorkflowNameCollisionExceptionTest extends TestCase
{
    public function testForSlug(): void
    {
        $exception = WorkflowNameCollisionException::forSlug('blog_publishing');

        self::assertStringContainsString('"blog_publishing"', $exception->getMessage());
        self::assertStringContainsString('"workflow.blog_publishing.*"', $exception->getMessage());
    }
}
