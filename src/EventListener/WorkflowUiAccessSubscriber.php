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
use function ltrim;

/**
 * Enforces {@see WorkflowUiAccessCheckerInterface} before Workflow UI controllers run.
 *
 * When the bundle is disabled ({@code nowo_workflow.enabled: false}) every Workflow UI route answers 404.
 */
final class WorkflowUiAccessSubscriber implements EventSubscriberInterface
{
    public const ROUTE_NAME_PREFIX = 'nowo_workflow_';

    public const CONTROLLER_NAMESPACE = 'Nowo\\WorkflowBundle\\Controller\\';

    /**
     * Creates the subscriber with the UI access checker and the web_ui.enabled flag.
     */
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

    /**
     * Answers 404 when the UI is disabled and 403 when the access checker denies the request.
     */
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

    /**
     * A Workflow UI request: a route named `nowo_workflow_*`, or any route whose controller is one of the bundle's UI
     * controllers — so a host importing the routes with a `name_prefix` (or renaming them) does not bypass the check.
     */
    public static function isWorkflowUiRoute(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');
        if (is_string($routeName) && str_starts_with($routeName, self::ROUTE_NAME_PREFIX)) {
            return true;
        }

        $controller = $request->attributes->get('_controller');

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }

    /**
     * Returns the kernel events this subscriber listens to.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST    => ['onKernelRequest', 30],
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }
}
