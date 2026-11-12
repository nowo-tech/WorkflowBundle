<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function is_array;
use function is_string;

/**
 * What a 1.13-style checker reads from the Symfony request and the token storage.
 */
final class TokenFacts
{
    public static function decide(string $predicate, Request $request, TokenStorageInterface $tokens): bool
    {
        $token  = $tokens->getToken();
        $authed = $token instanceof TokenInterface && $token->getUser() instanceof UserInterface;
        $route  = $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');

        return AccessPredicates::decide(
            $predicate,
            $request->getMethod(),
            is_string($route) ? $route : null,
            $request->getPathInfo(),
            is_array($params) ? $params : [],
            $authed ? $token->getUserIdentifier() : null,
            $authed ? array_values($token->getRoleNames()) : [],
            $authed && $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : null,
        );
    }
}
