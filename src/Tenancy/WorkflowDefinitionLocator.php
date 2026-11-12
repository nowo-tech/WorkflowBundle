<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowResolver;

/**
 * Picks the stored definition version a subject runs (since 1.15).
 *
 * Without tenancy: the enabled definition of the slug, exactly as 1.14.
 *
 * With tenancy, in this order:
 *
 * 1. the version the subject is pinned to (it started with it), when the host gives a subject reference; a pin is
 *    only honoured when it names that slug and a global template or the subject's own tenant (deny otherwise);
 * 2. the tenant's current published version of the slug;
 * 3. the global template's current published version.
 *
 * A definition of another tenant is never returned; a disabled version is "not found" (no fallback to the template
 * when the tenant disabled its own version).
 *
 * @internal
 */
final readonly class WorkflowDefinitionLocator
{
    public function __construct(
        private WorkflowDefinitionRepository $repository,
        private WorkflowResolver $resolver,
        private TenancyContext $tenancy,
        private SubjectPinStore $pins,
    ) {
    }

    public function tenancyEnabled(): bool
    {
        return $this->tenancy->enabled();
    }

    /**
     * The enabled definition version the subject runs for the slug.
     *
     * @throws WorkflowNotFoundException
     */
    public function forSubject(object $subject, string $slug): WorkflowDefinition
    {
        if (!$this->tenancy->enabled()) {
            return $this->enabledOrFail($this->repository->findOneBySlug($slug), $slug);
        }

        $tenant = $this->tenancy->tenantOf($subject, $slug);
        $pinned = $this->pinned($subject, $slug, $tenant);

        return $this->enabledOrFail($pinned ?? $this->repository->findForTenant($slug, $tenant), $slug);
    }

    /**
     * The definition version the subject runs, resolved from its class and context (match rules, priority), then
     * replaced by its pinned version of that slug when it has one.
     *
     * @throws WorkflowNotFoundException
     */
    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        $definition = $this->resolver->resolveForSubject($subject);
        if (!$this->tenancy->enabled()) {
            return $definition;
        }

        $pinned = $this->pinned($subject, $definition->getSlug(), $this->tenancy->tenantOf($subject, $definition->getSlug()));

        return $pinned instanceof WorkflowDefinition ? $this->enabledOrFail($pinned, $definition->getSlug()) : $definition;
    }

    /**
     * The definition resolved from an explicit context for the subject's tenant, then its pinned version.
     *
     * @throws WorkflowNotFoundException
     */
    public function resolveForContext(object $subject, WorkflowContext $context): WorkflowDefinition
    {
        if (!$this->tenancy->enabled()) {
            return $this->resolver->resolve($context);
        }

        $tenant     = $this->tenancy->tenantOf($subject);
        $definition = $this->resolver->resolveForTenant($context, $tenant);
        $pinned     = $this->pinned($subject, $definition->getSlug(), $tenant);

        return $pinned instanceof WorkflowDefinition ? $this->enabledOrFail($pinned, $definition->getSlug()) : $definition;
    }

    /**
     * Pins the subject on the version it just ran, unless it is pinned already (tenancy and a subject reference only).
     */
    public function pin(object $subject, WorkflowDefinition $definition): void
    {
        if (!$this->tenancy->enabled()) {
            return;
        }
        $reference = $this->tenancy->referenceOf($subject, $definition->getSlug());
        if (!$reference instanceof WorkflowSubjectRef) {
            return;
        }

        $this->pins->pin($this->tenancy->tenantOf($subject, $definition->getSlug()), $definition->getSlug(), $reference, $definition);
    }

    /**
     * Whether the subject may run this stored definition: it is the version {@see forSubject()} returns for it.
     */
    public function isExpected(object $subject, WorkflowDefinition $definition): bool
    {
        try {
            return $this->forSubject($subject, $definition->getSlug())->getId() === $definition->getId();
        } catch (WorkflowNotFoundException) {
            return false;
        }
    }

    private function pinned(object $subject, string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        $reference = $this->tenancy->referenceOf($subject, $slug);
        if (!$reference instanceof WorkflowSubjectRef) {
            return null;
        }
        $id = $this->pins->pinnedDefinitionId($tenant, $slug, $reference);
        if ($id === null) {
            return null;
        }

        $definition = $this->repository->find($id);
        if (!$definition instanceof WorkflowDefinition) {
            // The pinned version was deleted: the subject follows the current version.
            return null;
        }
        // A pin must name this slug, a published version and the subject's tenant or a global template: anything else
        // (a tampered row, a tenant key changed by the host) is refused, never followed.
        if ($definition->getSlug() !== $slug || $definition->isDraft()
            || !($definition->getTenantKey() === '' || $definition->belongsTo($tenant))) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        return $definition;
    }

    /**
     * @throws WorkflowNotFoundException
     */
    private function enabledOrFail(?WorkflowDefinition $definition, string $slug): WorkflowDefinition
    {
        if (!$definition instanceof WorkflowDefinition || !$definition->isEnabled()) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        return $definition;
    }
}
