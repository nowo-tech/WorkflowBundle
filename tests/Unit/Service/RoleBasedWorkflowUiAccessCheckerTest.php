<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
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

    public function testTheFrameworkFreePortAsksTheAuthorizationChecker(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, null, false], ['ROLE_EDITOR', null, null, true]]);

        $check = new WorkflowUiAccessCheck('GET');
        self::assertTrue((new RoleBasedWorkflowUiAccessChecker(['ROLE_ADMIN', 'ROLE_EDITOR'], $auth))->allows($check));
        self::assertFalse((new RoleBasedWorkflowUiAccessChecker([], $auth))->allows($check));
    }
}
