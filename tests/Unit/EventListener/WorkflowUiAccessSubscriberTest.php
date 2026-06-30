<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class WorkflowUiAccessSubscriberTest extends TestCase
{
    public function testAllowsWorkflowUiRouteWhenCheckerGrantsAccess(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::once())
            ->method('isGranted')
            ->willReturn(true);

        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_dashboard');

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new WorkflowUiAccessSubscriber($checker))->onKernelController($event);

        self::addToAssertionCount(1);
    }

    public function testDeniesWorkflowUiRouteWhenCheckerRejectsAccess(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::once())
            ->method('isGranted')
            ->willReturn(false);

        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_definition_index');

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->expectException(AccessDeniedHttpException::class);

        (new WorkflowUiAccessSubscriber($checker))->onKernelController($event);
    }

    public function testIgnoresNonWorkflowUiRoutes(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $request = new Request();
        $request->attributes->set('_route', 'app_home');

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new WorkflowUiAccessSubscriber($checker))->onKernelController($event);

        self::addToAssertionCount(1);
    }

    public function testIgnoresSubRequests(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_dashboard');

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        (new WorkflowUiAccessSubscriber($checker))->onKernelController($event);

        self::addToAssertionCount(1);
    }
}
