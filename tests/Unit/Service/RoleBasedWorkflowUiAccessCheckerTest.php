<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RoleBasedWorkflowUiAccessCheckerTest extends TestCase
{
    public function testDeniesWhenNoMatchingRole(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $checker = new RoleBasedWorkflowUiAccessChecker(['ROLE_ADMIN'], $auth);

        self::assertFalse($checker->isGranted(Request::create('/workflow')));
    }

    public function testGrantsWhenRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $checker = new RoleBasedWorkflowUiAccessChecker(['ROLE_ADMIN'], $auth);

        self::assertTrue($checker->isGranted(Request::create('/workflow')));
    }

    public function testDeniesWhenRequiredRolesEmpty(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);

        $checker = new RoleBasedWorkflowUiAccessChecker([], $auth);

        self::assertFalse($checker->isGranted(Request::create('/workflow')));
    }
}
