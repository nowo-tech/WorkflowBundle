<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

/**
 * Controls access to the built-in Workflow CRUD UI. Framework-free twin of the deprecated
 * {@see WorkflowUiAccessCheckerInterface} (since 1.14, REQ-ARCH-001 rule 3): it receives a
 * {@see WorkflowUiAccessCheck} value object instead of the Symfony `Request`.
 *
 * Point `nowo_workflow.security.access_checker` at a service implementing it, or alias this interface to your service.
 * When no custom service is configured, the bundle wires a role-based policy (`security.access_roles`). A service that
 * implements both interfaces is asked through this one. Answer false whenever the request must not reach the UI: the
 * bundle answers 403 (fail closed); an exception thrown here is never turned into a grant.
 */
interface WorkflowUiAccessPolicyInterface
{
    /**
     * Tells whether this main request may access the Workflow admin UI.
     */
    public function allows(WorkflowUiAccessCheck $check): bool;
}
