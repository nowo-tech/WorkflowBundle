<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The subscriber asks the framework-free port with a VO (since 1.14; the only port since 2.0) and denies when no
 * policy is wired.
 */
final class WorkflowUiAccessSubscriberPortsTest extends TestCase
{
    #[Test]
    public function thePolicyGetsAVoWithTheTokenUser(): void
    {
        $seen   = null;
        $policy = $this->createMock(WorkflowUiAccessPolicyInterface::class);
        $policy->expects(self::once())->method('allows')->willReturnCallback(static function (WorkflowUiAccessCheck $check) use (&$seen): bool {
            $seen = $check;

            return true;
        });
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        (new WorkflowUiAccessSubscriber(null, true, $policy, $tokens))->onKernelController($this->event());

        self::assertInstanceOf(WorkflowUiAccessCheck::class, $seen);
        self::assertSame('nowo_workflow_dashboard', $seen->routeName);
        self::assertSame('alice', $seen->actor?->userIdentifier);
    }

    #[Test]
    public function thePolicyWithoutTokenStorageSeesAnAnonymousRequestAndDenies(): void
    {
        $policy = $this->createMock(WorkflowUiAccessPolicyInterface::class);
        $policy->method('allows')->willReturnCallback(static fn (WorkflowUiAccessCheck $check): bool => $check->isAuthenticated());

        $this->expectException(AccessDeniedHttpException::class);

        (new WorkflowUiAccessSubscriber(null, true, $policy))->onKernelController($this->event());
    }

    #[Test]
    public function aGrantingPolicyLetsTheRequestThrough(): void
    {
        $allow = $this->createMock(WorkflowUiAccessPolicyInterface::class);
        $allow->expects(self::once())->method('allows')->willReturn(true);

        (new WorkflowUiAccessSubscriber(null, true, $allow))->onKernelController($this->event());
    }

    #[Test]
    public function noPolicyWiredDenies(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new WorkflowUiAccessSubscriber())->onKernelController($this->event());
    }

    private function event(): ControllerEvent
    {
        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_dashboard');

        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): string => 'ok', $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
