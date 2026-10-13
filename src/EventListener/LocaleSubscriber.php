<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the stored workflow UI locale to Workflow UI requests only.
 *
 * Runs after the router (priority 32) so {@code _route} is known; host application routes keep their own
 * locale and no session is opened for them.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !WorkflowUiAccessSubscriber::isWorkflowUiRoute($event->getRequest())) {
            return;
        }

        $event->getRequest()->setLocale($this->localeManager->resolveLocale());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }
}
