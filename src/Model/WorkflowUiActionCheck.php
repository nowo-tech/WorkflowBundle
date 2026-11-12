<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;

/**
 * One Workflow UI action to authorise (since 1.15), given to
 * {@see WorkflowUiActionCheckerInterface}.
 *
 * - `tenant`: the scope of the page, from the host's UI tenant resolver (null = the global templates);
 * - `workflowSlug` / `definitionTenant`: the definition acted on (null for list, dashboard and create);
 *   `definitionTenant` differs from `tenant` only when a tenant views or clones a global template;
 * - `request`: the framework-free request data of 1.14 (route name, scalar route parameters such as the
 *   organization UUID of a host prefix, path, actor).
 */
final readonly class WorkflowUiActionCheck
{
    public function __construct(
        public WorkflowUiAction $action,
        public ?WorkflowTenant $tenant,
        public WorkflowUiAccessCheck $request,
        public ?string $workflowSlug = null,
        public ?WorkflowTenant $definitionTenant = null,
    ) {
    }
}
