<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Denies Workflow UI access unless the user has at least one configured role.
 */
final readonly class RoleBasedWorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface, WorkflowUiAccessCheckerInterface
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
     * Grants the request when the user has at least one configured role (through the Symfony authorization checker,
     * so the role hierarchy and the host voters apply); an empty role list denies.
     */
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        return $this->hasRequiredRole();
    }

    /**
     * Deprecated port (removed in 2.0): same answer as {@see allows()}.
     */
    public function isGranted(Request $request): bool
    {
        return $this->hasRequiredRole();
    }

    private function hasRequiredRole(): bool
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
