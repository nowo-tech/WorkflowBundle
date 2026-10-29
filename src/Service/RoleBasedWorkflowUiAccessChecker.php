<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Denies Workflow UI access unless the user has at least one configured role.
 */
final readonly class RoleBasedWorkflowUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    /**
     * Creates the checker with the required roles and the Symfony authorization checker.
     *
     * @param list<string> $requiredRoles
     */
    public function __construct(
        private array $requiredRoles,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Grants the request when the user has at least one configured role; an empty role list denies.
     */
    public function isGranted(Request $request): bool
    {
        // Empty access_roles = deny (fail-closed). Use allow_unauthenticated or a custom checker for demos.
        if ($this->requiredRoles === []) {
            return false;
        }

        foreach ($this->requiredRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
