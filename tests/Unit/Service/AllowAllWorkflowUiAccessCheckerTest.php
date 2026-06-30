<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AllowAllWorkflowUiAccessCheckerTest extends TestCase
{
    public function testIsGrantedAlwaysReturnsTrue(): void
    {
        $checker = new AllowAllWorkflowUiAccessChecker();

        self::assertTrue($checker->isGranted(new Request()));
    }
}
