<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * Controls access to the built-in Workflow CRUD UI.
 *
 * Register a custom implementation and alias it to this interface to protect
 * `/workflow` routes. When no custom service is configured, all requests are allowed.
 */
interface WorkflowUiAccessCheckerInterface
{
    public function isGranted(Request $request): bool;
}
