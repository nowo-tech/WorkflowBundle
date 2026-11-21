<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use PHPUnit\Framework\TestCase;

final class DenyAllWorkflowUiAccessCheckerTest extends TestCase
{
    public function testIsGrantedAlwaysReturnsFalse(): void
    {
        $checker = new DenyAllWorkflowUiAccessChecker();

        self::assertFalse($checker->allows(new WorkflowUiAccessCheck('GET')));
    }

    public function testTheFrameworkFreePortGivesTheSameAnswer(): void
    {
        self::assertFalse((new DenyAllWorkflowUiAccessChecker())->allows(new WorkflowUiAccessCheck('GET')));
    }
}
