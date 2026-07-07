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
     * @param list<string> $requiredRoles
     */
    public function __construct(
        private array $requiredRoles,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function isGranted(Request $request): bool
    {
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
