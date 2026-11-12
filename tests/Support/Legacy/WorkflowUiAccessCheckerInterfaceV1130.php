<?php

declare(strict_types=1);

// Verbatim copy of the v1.13.0 WorkflowUiAccessCheckerInterface (git show v1.13.0:src/Contract/WorkflowUiAccessCheckerInterface.php): reference of the 1.14
// access-decision equivalence matrix. Only the namespace and class / interface names were renamed. Do not edit.

namespace Nowo\WorkflowBundle\Tests\Support\Legacy;

use Symfony\Component\HttpFoundation\Request;

/**
 * Controls access to the built-in Workflow CRUD UI.
 *
 * Register a custom implementation and alias it to this interface to protect
 * `/workflow` routes. When no custom service is configured, the bundle wires a role-based
 * checker (security.access_roles); the fallback policy denies every request.
 */
interface WorkflowUiAccessCheckerInterfaceV1130
{
    /**
     * Tells whether the current request may access the Workflow admin UI.
     */
    public function isGranted(Request $request): bool;
}
