<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowTenant;

/**
 * Host port (since 1.15): the tenant a workflow subject belongs to. Configuring it
 * (`nowo_workflow.tenancy.tenant_resolver`) turns tenancy on.
 *
 * At runtime the definition of the subject's tenant wins, else the global template; a definition of another tenant
 * is never used. Resolved on every call (never cached by the bundle), so it must be cheap and stateless.
 */
interface WorkflowTenantResolverInterface
{
    /**
     * The subject's tenant, or null when the subject belongs to no tenant (only global templates apply).
     */
    public function tenantOf(WorkflowSubject $subject): ?WorkflowTenant;
}
