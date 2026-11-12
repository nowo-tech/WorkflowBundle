<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/**
 * Host port (since 1.15): the tenant scope of a Workflow UI request (`nowo_workflow.tenancy.ui.tenant_resolver`).
 *
 * The request VO carries the route parameters, so a host that mounts the routes under
 * `/organizations/{uuid}/settings/workflows` reads `$request->routeParam('uuid')`. Return null for the global scope
 * (the templates); the action checker still decides whether the actor may use it. Throw (or return a tenant the actor
 * may not use and deny it in the action checker) for an unknown organization.
 */
interface WorkflowUiTenantResolverInterface
{
    public function tenantFor(WorkflowUiAccessCheck $request): ?WorkflowTenant;
}
