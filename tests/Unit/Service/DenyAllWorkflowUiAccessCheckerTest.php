<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DenyAllWorkflowUiAccessCheckerTest extends TestCase
{
    public function testIsGrantedAlwaysReturnsFalse(): void
    {
        $checker = new DenyAllWorkflowUiAccessChecker();

        self::assertFalse($checker->isGranted(new Request()));
    }
}
