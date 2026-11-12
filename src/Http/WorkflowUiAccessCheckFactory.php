<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Http;

use Nowo\WorkflowBundle\Model\WorkflowActor;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;
use function is_string;

/**
 * Builds the framework-free {@see WorkflowUiAccessCheck} of a Symfony request at the HTTP edge (since 1.14).
 *
 * Copies the method, `_route`, `_controller` (when a string), the path info and the `_route_params`, and the user of
 * the security token (identifier, role names, impersonator, the user object hidden in the VO). Never the cookies,
 * headers, query string, body or session. Stateless.
 *
 * @internal
 */
final class WorkflowUiAccessCheckFactory
{
    public static function fromRequest(Request $request, ?TokenInterface $token = null): WorkflowUiAccessCheck
    {
        $route       = $request->attributes->get('_route');
        $controller  = $request->attributes->get('_controller');
        $routeParams = $request->attributes->get('_route_params');

        return new WorkflowUiAccessCheck(
            $request->getMethod(),
            is_string($route) ? $route : null,
            is_string($controller) ? $controller : null,
            self::actor($token),
            $request->getPathInfo(),
            is_array($routeParams) ? $routeParams : [],
        );
    }

    /**
     * The authenticated user of the token, or null for no token or a token without a user.
     */
    public static function actor(?TokenInterface $token): ?WorkflowActor
    {
        $user = $token?->getUser();
        if (!$token instanceof TokenInterface || !$user instanceof UserInterface) {
            return null;
        }

        return new WorkflowActor(
            $token->getUserIdentifier(),
            $token->getRoleNames(),
            $user,
            $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : null,
        );
    }
}
