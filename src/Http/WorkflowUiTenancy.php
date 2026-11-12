<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Http;

use Nowo\WorkflowBundle\Contract\WorkflowSubjectVariablesProviderInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTransitionPermissionCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiTenantResolverInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Model\WorkflowUiAction;
use Nowo\WorkflowBundle\Model\WorkflowUiActionCheck;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tenant scope and per-action authorisation of the Workflow UI controllers (since 1.15). Stateless: the scope is
 * asked to the host on every call, from the request's route parameters.
 *
 * Scope rules with tenancy (deny by default):
 *
 * - a definition of another tenant is never shown, edited or acted on: 404 (its existence is not revealed);
 * - a tenant may view a published global template and clone it, never edit, publish or delete it: 403;
 * - the global scope (the UI tenant resolver returns null) sees only global definitions.
 *
 * Every action also asks the host's {@see WorkflowUiActionCheckerInterface} (view / edit / publish), on top of the
 * 1.14 {@see WorkflowUiAccessPolicyInterface} the subscriber already asked.
 *
 * @internal
 */
final readonly class WorkflowUiTenancy
{
    /**
     * @param array<string, string> $subjectClasses `tenancy.subject_classes`: subject class => marking property
     */
    public function __construct(
        private bool $tenancy = false,
        private ?WorkflowUiTenantResolverInterface $tenantResolver = null,
        private ?WorkflowUiActionCheckerInterface $actionChecker = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?WorkflowTransitionPermissionCheckerInterface $permissionChecker = null,
        private ?WorkflowSubjectVariablesProviderInterface $variablesProvider = null,
        private array $subjectClasses = [],
    ) {
    }

    /**
     * Audit NO M2: in a tenant scope a new definition may only bind a subject class of `tenancy.subject_classes`, with
     * the marking property configured there (forced here). The global scope (null) and hosts without tenancy are not
     * restricted.
     *
     * @return bool false when the subject class is not allowed for tenants
     */
    public function bindTenantSubject(WorkflowDefinition $definition, ?WorkflowTenant $tenant): bool
    {
        if (!$this->tenancy || !$tenant instanceof WorkflowTenant) {
            return true;
        }
        $marking = $this->subjectClasses[$definition->getSubjectClass()] ?? null;
        if ($marking === null) {
            return false;
        }
        $definition->setMarkingProperty($marking);

        return true;
    }

    public function enabled(): bool
    {
        return $this->tenancy;
    }

    /**
     * The tenant scope of the request (null = global; always null without tenancy).
     */
    public function tenant(Request $request): ?WorkflowTenant
    {
        if (!$this->tenancy || !$this->tenantResolver instanceof WorkflowUiTenantResolverInterface) {
            return null;
        }

        return $this->tenantResolver->tenantFor($this->accessCheck($request));
    }

    /**
     * Throws 403 unless the host's action checker allows the action (no checker: allowed, the 1.14 policy decided).
     */
    public function denyUnlessAllowed(Request $request, WorkflowUiAction $action, ?WorkflowTenant $tenant, ?WorkflowDefinition $definition = null): void
    {
        if (!$this->actionChecker instanceof WorkflowUiActionCheckerInterface) {
            if ($this->tenancy) {
                // Unreachable through the bundle configuration (the build fails); fail closed anyway.
                throw new AccessDeniedHttpException('No Workflow UI action checker is configured.');
            }

            return;
        }

        if (!$this->actionChecker->allows(new WorkflowUiActionCheck($action, $tenant, $this->accessCheck($request), $definition?->getSlug(), $definition?->getTenant()))) {
            throw new AccessDeniedHttpException('This Workflow UI action is denied.');
        }
    }

    /**
     * The definition as seen from the scope: 404 for another tenant's definition (and, for a tenant, a global draft),
     * 403 for a global template a tenant tries to change. Without tenancy only global definitions exist.
     */
    public function assertInScope(WorkflowDefinition $definition, ?WorkflowTenant $tenant, bool $write): void
    {
        if ($definition->belongsTo($tenant)) {
            return;
        }

        if ($tenant instanceof WorkflowTenant && $definition->getTenantKey() === '' && !$definition->isDraft()) {
            if ($write) {
                throw new AccessDeniedHttpException('A global template cannot be changed from a tenant scope; clone it first.');
            }

            return;
        }

        throw new NotFoundHttpException('Workflow definition not found.');
    }

    /**
     * The guard fields the transition editor shows: permission (with a permission checker), condition variables (with
     * a variables provider; null = hidden) and the automatic flag (with either).
     *
     * @return array{permission: bool, variables: list<string>|null, automatic: bool}
     */
    public function guardFields(WorkflowDefinition $definition): array
    {
        $variables = null;
        if ($this->variablesProvider instanceof WorkflowSubjectVariablesProviderInterface && class_exists($definition->getSubjectClass())) {
            $variables = $this->variablesProvider->variableNames($definition->getSubjectClass());
        } elseif ($this->variablesProvider instanceof WorkflowSubjectVariablesProviderInterface) {
            $variables = [];
        }
        $permission = $this->permissionChecker instanceof WorkflowTransitionPermissionCheckerInterface;

        return ['permission' => $permission, 'variables' => $variables, 'automatic' => $permission || $variables !== null];
    }

    private function accessCheck(Request $request): WorkflowUiAccessCheck
    {
        return WorkflowUiAccessCheckFactory::fromRequest($request, $this->tokenStorage?->getToken());
    }
}
