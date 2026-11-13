<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\View;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;

/**
 * The tenant scope a Workflow UI page renders for (since 1.15): decides the row and header actions (edit a draft,
 * new draft, publish, clone a template) and the badges. Built per request.
 *
 * @internal
 */
final readonly class WorkflowUiScope
{
    public function __construct(
        public bool $tenancy = false,
        public ?WorkflowTenant $tenant = null,
    ) {
    }

    /**
     * A global template seen from a tenant scope (read-only there; it can be cloned).
     */
    public function isTemplate(WorkflowDefinition $definition): bool
    {
        return $this->tenancy && $this->tenant instanceof WorkflowTenant && $definition->getTenantKey() === '';
    }

    /**
     * A published version of this tenant's own definition: it can be retired (since 1.16).
     */
    public function canRetire(WorkflowDefinition $definition): bool
    {
        return $this->tenancy && $this->tenant instanceof WorkflowTenant && $definition->isPublished() && $definition->belongsTo($this->tenant);
    }

    /**
     * A retired version of this tenant's own definition: it can be reinstated (since 1.16).
     */
    public function canReinstate(WorkflowDefinition $definition): bool
    {
        return $this->tenancy && $this->tenant instanceof WorkflowTenant && $definition->isRetired() && $definition->belongsTo($this->tenant);
    }

    /**
     * Whether the editor may open the definition in this scope (without tenancy: always).
     */
    public function isEditable(WorkflowDefinition $definition): bool
    {
        return !$this->tenancy || ($definition->isDraft() && $definition->belongsTo($this->tenant));
    }
}
