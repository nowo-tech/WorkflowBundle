<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use InvalidArgumentException;
use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowPinResult;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowResolver;

use function array_diff;
use function implode;
use function sprintf;

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
     * Pins the subject on the version it is about to run, before the transition is applied (audit NO M3), and returns
     * the version it is pinned to: a concurrent request may have pinned it on another version first, which then wins.
     * Tenancy and a subject reference only; the definition is returned unchanged otherwise.
     *
     * @throws WorkflowNotFoundException when the winning pin is not valid for the subject
     */
    public function ensurePinned(object $subject, WorkflowDefinition $definition): WorkflowDefinition
    {
        if (!$this->tenancy->enabled()) {
            return $definition;
        }
        $slug      = $definition->getSlug();
        $reference = $this->tenancy->referenceOf($subject, $slug);
        if (!$reference instanceof WorkflowSubjectRef) {
            return $definition;
        }

        $pinned = $this->pins->pin($this->tenancy->tenantOf($subject, $slug), $slug, $reference, $definition);

        return $pinned === $definition->getId() ? $definition : $this->forSubject($subject, $slug);
    }

    /**
     * Pins the subject explicitly, without applying anything (since 1.16, {@see WorkflowRuntimeInterface::pin()}).
     *
     * @param callable(WorkflowDefinition): void $assertSupported throws when the subject is not supported
     * @param callable(WorkflowDefinition): list<string> $placesOf the subject's current places as that version
     *                                                             reads them (no event, no initial marking)
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidArgumentException
     * @throws LogicException when tenancy is off
     */
    public function pinSubject(object $subject, string $slug, WorkflowPinMode $mode, callable $assertSupported, callable $placesOf): WorkflowPinResult
    {
        if (!$this->tenancy->enabled()) {
            throw new LogicException('Pinning a subject needs tenancy (nowo_workflow.tenancy.tenant_resolver).');
        }
        $reference = $this->tenancy->referenceOf($subject, $slug);
        if (!$reference instanceof WorkflowSubjectRef) {
            throw new InvalidArgumentException(sprintf('The subject has no reference for workflow "%s" (WorkflowSubjectReferenceResolverInterface): it cannot be pinned.', $slug));
        }
        $tenant = $this->tenancy->tenantOf($subject, $slug);
        // An existing pin of another tenant (or a draft, another slug) is refused here, as in every other call.
        $pinned = $this->pinned($subject, $slug, $tenant);
        if ($pinned instanceof WorkflowDefinition && $mode === WorkflowPinMode::KeepExisting) {
            $assertSupported($pinned);

            return $this->pinResult($this->enabledOrFail($pinned, $slug), $reference, false);
        }

        // The version a new subject of the tenant starts with now: never a draft, a retired clone or another tenant's.
        $target = $this->enabledOrFail($this->repository->findForTenant($slug, $tenant), $slug);
        $assertSupported($target);
        if (!$pinned instanceof WorkflowDefinition) {
            $winner = $this->pins->pin($tenant, $slug, $reference, $target);

            return $winner === $target->getId() ? $this->pinResult($target, $reference, true) : $this->pinResult($this->forSubject($subject, $slug), $reference, false);
        }
        if ($pinned->getId() === $target->getId()) {
            return $this->pinResult($target, $reference, false);
        }

        $missing = array_diff($placesOf($target), $target->getPlaceNames());
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('The subject cannot be moved to version %d of workflow "%s": its place(s) "%s" do not exist there.', $target->getVersion(), $slug, implode('", "', $missing)));
        }
        $this->pins->repin($tenant, $slug, $reference, $target);

        return $this->pinResult($target, $reference, false, true, $pinned->getVersion());
    }

    private function pinResult(WorkflowDefinition $definition, WorkflowSubjectRef $subject, bool $created, bool $replaced = false, ?int $previousVersion = null): WorkflowPinResult
    {
        return new WorkflowPinResult($definition->getSlug(), $definition->getVersion(), $definition->getTenantKey() === '' ? null : $definition->getTenantKey(), $subject, $created, $replaced, $previousVersion);
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
            || $definition->getTenantKey() !== '' && !$definition->belongsTo($tenant)) {
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
