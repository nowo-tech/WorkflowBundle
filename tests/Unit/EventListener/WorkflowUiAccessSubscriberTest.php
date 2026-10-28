<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

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

    /** Routes imported with a `name_prefix` keep the bundle controller: still guarded. */
    public function testDeniesBundleControllersUnderAForeignRouteName(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->willReturn(false);

        $request = new Request();
        $request->attributes->set('_route', 'cms_definitions');
        $request->attributes->set('_controller', 'Nowo\\WorkflowBundle\\Controller\\WorkflowDefinitionController::index');

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        self::assertTrue(WorkflowUiAccessSubscriber::isWorkflowUiRoute($request));
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

    public function testSubscribedEvents(): void
    {
        self::assertSame(
            [
                KernelEvents::REQUEST    => ['onKernelRequest', 30],
                KernelEvents::CONTROLLER => ['onKernelController', 0],
            ],
            WorkflowUiAccessSubscriber::getSubscribedEvents(),
        );
    }

    public function testDisabledBundleAnswers404OnRequestForWorkflowUiRoute(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $request = Request::create('/workflow');
        $request->attributes->set('_route', 'nowo_workflow_dashboard');

        $this->expectException(NotFoundHttpException::class);

        (new WorkflowUiAccessSubscriber($checker, false))->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));
    }

    public function testDisabledBundleIgnoresHostRoutesOnRequest(): void
    {
        $request = Request::create('/');
        $request->attributes->set('_route', 'app_home');

        (new WorkflowUiAccessSubscriber($this->createMock(WorkflowUiAccessCheckerInterface::class), false))->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::addToAssertionCount(1);
    }

    public function testEnabledBundleDoesNothingOnRequest(): void
    {
        $request = Request::create('/workflow');
        $request->attributes->set('_route', 'nowo_workflow_dashboard');

        (new WorkflowUiAccessSubscriber($this->createMock(WorkflowUiAccessCheckerInterface::class)))->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::addToAssertionCount(1);
    }

    public function testDisabledBundleAnswers404OnControllerEvenForAllowAllChecker(): void
    {
        $checker = $this->createMock(WorkflowUiAccessCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);

        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_definition_index');

        $this->expectException(NotFoundHttpException::class);

        (new WorkflowUiAccessSubscriber($checker, false))->onKernelController(new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::SUB_REQUEST,
        ));
    }
}
