<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;

/**
 * Enforces {@see WorkflowUiAccessCheckerInterface} before Workflow UI controllers run.
 */
final class WorkflowUiAccessSubscriber implements EventSubscriberInterface
{
    private const ROUTE_NAME_PREFIX = 'nowo_workflow_';

    public function __construct(
        private readonly WorkflowUiAccessCheckerInterface $accessChecker,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $routeName = $event->getRequest()->attributes->get('_route');
        if (!is_string($routeName) || !str_starts_with($routeName, self::ROUTE_NAME_PREFIX)) {
            return;
        }

        if (!$this->accessChecker->isGranted($event->getRequest())) {
            throw new AccessDeniedHttpException('Access to the Workflow UI is denied.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }
}
