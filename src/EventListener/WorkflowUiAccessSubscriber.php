<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\Http\WorkflowUiAccessCheckFactory;
use Nowo\WorkflowBundle\Model\WorkflowActor;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_string;
use function ltrim;

/**
 * Enforces the Workflow UI access policy before Workflow UI controllers run.
 *
 * Since 1.14 the subscriber asks the framework-free {@see WorkflowUiAccessPolicyInterface} (with a
 * {@see WorkflowUiAccessCheck} built from the main request and the security token) and/or
 * the deprecated {@see WorkflowUiAccessCheckerInterface} (with the Symfony request, exactly as in 1.13).
 * {@see WorkflowUiSecurityPass} decides which: the policy, the deprecated checker (a host service on the deprecated
 * port only, or a host decorator of the deprecated port), or both. When both are wired, both must grant. When neither
 * is wired, every request is denied (fail closed).
 *
 * When the bundle is disabled ({@code nowo_workflow.enabled: false}) every Workflow UI route answers 404.
 */
final class WorkflowUiAccessSubscriber implements EventSubscriberInterface
{
    public const ROUTE_NAME_PREFIX = 'nowo_workflow_';

    public const CONTROLLER_NAMESPACE = 'Nowo\\WorkflowBundle\\Controller\\';

    /**
     * Creates the subscriber with the access ports, the web_ui.enabled flag and the token storage (for the
     * {@see WorkflowActor} of the policy).
     *
     * @param WorkflowUiAccessCheckerInterface|null $accessChecker deprecated port, asked with the Symfony request
     * @param WorkflowUiAccessPolicyInterface|null $accessPolicy framework-free port (1.14)
     */
    public function __construct(
        private readonly ?WorkflowUiAccessCheckerInterface $accessChecker = null,
        private readonly bool $enabled = true,
        private readonly ?WorkflowUiAccessPolicyInterface $accessPolicy = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
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

        if (!$this->isGranted($event->getRequest())) {
            throw new AccessDeniedHttpException('Access to the Workflow UI is denied.');
        }
    }

    /**
     * Every wired port must grant; no port wired = deny.
     */
    private function isGranted(Request $request): bool
    {
        if (!$this->accessPolicy instanceof WorkflowUiAccessPolicyInterface && !$this->accessChecker instanceof WorkflowUiAccessCheckerInterface) {
            return false;
        }

        if ($this->accessPolicy instanceof WorkflowUiAccessPolicyInterface
            && !$this->accessPolicy->allows(WorkflowUiAccessCheckFactory::fromRequest($request, $this->tokenStorage?->getToken()))) {
            return false;
        }

        return !$this->accessChecker instanceof WorkflowUiAccessCheckerInterface || $this->accessChecker->isGranted($request);
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
