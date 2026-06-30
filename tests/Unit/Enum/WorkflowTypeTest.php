<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Enum;

use Nowo\WorkflowBundle\Enum\WorkflowType;
use PHPUnit\Framework\TestCase;

final class WorkflowTypeTest extends TestCase
{
    public function testLabels(): void
    {
        self::assertSame('State machine', WorkflowType::StateMachine->label());
        self::assertSame('Workflow', WorkflowType::Workflow->label());
    }
}
