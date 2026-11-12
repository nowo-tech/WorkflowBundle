<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * Controls access to the built-in Workflow CRUD UI.
 *
 * Register a custom implementation and point `nowo_workflow.security.access_checker` at it to protect the `/workflow`
 * routes. When no custom service is configured, the bundle wires a role-based checker (security.access_roles); the
 * fallback policy denies every request.
 *
 * @deprecated since 1.14, implement {@see WorkflowUiAccessPolicyInterface} instead (framework-free, REQ-ARCH-001
 *             rule 3); removed in 2.0. A service that implements only this interface keeps working (the bundle calls it
 *             as in 1.13) and triggers a deprecation when the container is built.
 */
interface WorkflowUiAccessCheckerInterface
{
    /**
     * Tells whether the current request may access the Workflow admin UI.
     */
    public function isGranted(Request $request): bool;
}
