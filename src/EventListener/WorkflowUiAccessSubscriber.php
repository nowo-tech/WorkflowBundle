<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;

/**
 * Enforces {@see WorkflowUiAccessCheckerInterface} before Workflow UI controllers run.
 *
 * When the bundle is disabled ({@code nowo_workflow.enabled: false}) every Workflow UI route answers 404.
 */
final class WorkflowUiAccessSubscriber implements EventSubscriberInterface
{
    public const ROUTE_NAME_PREFIX = 'nowo_workflow_';

    public function __construct(
        private readonly WorkflowUiAccessCheckerInterface $accessChecker,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Runs right after the router (priority 32) so a disabled UI is unreachable before any other bundle listener.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled && self::isWorkflowUiRoute($event->getRequest())) {
            throw new NotFoundHttpException('The Workflow UI is disabled.');
        }
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!self::isWorkflowUiRoute($event->getRequest())) {
            return;
        }

        if (!$this->enabled) {
            throw new NotFoundHttpException('The Workflow UI is disabled.');
        }

        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->accessChecker->isGranted($event->getRequest())) {
            throw new AccessDeniedHttpException('Access to the Workflow UI is denied.');
        }
    }

    public static function isWorkflowUiRoute(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');

        return is_string($routeName) && str_starts_with($routeName, self::ROUTE_NAME_PREFIX);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST    => ['onKernelRequest', 30],
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }
}
