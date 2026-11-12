<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Contracts\Service\ResetInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function str_starts_with;

/**
 * Lets a host mount the Workflow UI under a prefix with route variables, e.g.
 * `/organizations/{uuid}/settings/workflows` (since 1.15): while a Workflow UI request runs, the prefix variables of
 * its route are router context parameters, so every bundle link (`path('nowo_workflow_…')`, redirects) keeps them.
 *
 * Worker-safe (kernel reset false): the parameters are removed when the request finishes and, should it never finish,
 * at the start of the next main request (audit NO L2), and at kernel.reset.
 *
 * @internal
 */
final class WorkflowUiRouteContextSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** Route variables of the bundle's own routes (never prefix variables). */
    private const OWN_VARIABLES = ['id', 'slug'];

    /**
     * The prefix variables this subscriber put in the router context (name => value), removed at the start of the next
     * main request at the latest (audit NO L2), even when the previous request never finished.
     *
     * @var array<string, string>
     */
    // @igor-ignore - Names of the router context parameters set for the current Workflow UI request; removed at finish_request and at every main request start.
    private array $set = [];

    public function __construct(
        private readonly ?RequestContextAwareInterface $router = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router (32) has matched the route.
            KernelEvents::REQUEST        => ['onKernelRequest', 31],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->reset();
        if (!$this->router instanceof RequestContextAwareInterface || !WorkflowUiAccessSubscriber::isWorkflowUiRoute($event->getRequest())) {
            return;
        }

        $context = $this->router->getContext();
        foreach (self::prefixVariables($event->getRequest()) as $name => $value) {
            // @igor-ignore - Prefix variables of the current Workflow UI request only; removed at kernel.finish_request.
            $context->setParameter($name, $value);
            // @igor-ignore - Remembered so the next main request removes it even if this one never finishes.
            $this->set[$name] = $value;
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    /**
     * Removes the parameters this subscriber set (unless something else changed them since).
     */
    public function reset(): void
    {
        if ($this->set !== [] && $this->router instanceof RequestContextAwareInterface) {
            $context    = $this->router->getContext();
            $parameters = $context->getParameters();
            foreach ($this->set as $name => $value) {
                if (($parameters[$name] ?? null) === $value) {
                    unset($parameters[$name]);
                }
            }
            // @igor-ignore - Removes the prefix variables this subscriber set for the finished request.
            $context->setParameters($parameters);
        }
        $this->set = [];
    }

    /**
     * The scalar route parameters of a Workflow UI request that are not the bundle's own variables.
     *
     * @return array<string, string>
     */
    public static function prefixVariables(Request $request): array
    {
        $params = $request->attributes->get('_route_params');
        if (!is_array($params)) {
            return [];
        }

        $variables = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || str_starts_with($name, '_') || in_array($name, self::OWN_VARIABLES, true)) {
                continue;
            }
            if (is_string($value) || is_int($value)) {
                $variables[$name] = (string) $value;
            }
        }

        return $variables;
    }
}
