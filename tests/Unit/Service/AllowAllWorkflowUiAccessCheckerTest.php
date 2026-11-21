<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use PHPUnit\Framework\TestCase;

final class AllowAllWorkflowUiAccessCheckerTest extends TestCase
{
    public function testIsGrantedAlwaysReturnsTrue(): void
    {
        $checker = new AllowAllWorkflowUiAccessChecker();

        self::assertTrue($checker->allows(new WorkflowUiAccessCheck('GET')));
    }

    public function testTheFrameworkFreePortGivesTheSameAnswer(): void
    {
        self::assertTrue((new AllowAllWorkflowUiAccessChecker())->allows(new WorkflowUiAccessCheck('GET')));
    }
}
