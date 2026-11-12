<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

/**
 * `tenancy.lock_clone_structure` (since 1.16): a tenant definition of a slug that has a global template (a clone, or a
 * tenant definition published before the template existed) keeps the template's structure, see
 * {@see WorkflowDefinition::structure()} (initial place, place names, transitions with their `from` / `to` places;
 * labels, guards, conditions and the automatic flag are not part of it).
 *
 * Checked when a draft is published, reinstated or cloned published ({@see assertAllowed()}) and every time a tenant
 * version is resolved ({@see violates()}, audit OD M3): a version that diverged (published before the lock was turned
 * on, or before the template existed) is not used for new subjects. Stateless.
 *
 * @internal
 */
final readonly class CloneStructureLock
{
    public function __construct(
        private WorkflowDefinitionRepository $repository,
        private bool $enabled = false,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function isLocked(WorkflowDefinition $definition): bool
    {
        if (!$this->enabled || $definition->getTenantKey() === '') {
            return false;
        }

        return $definition->isTemplateClone() || $this->repository->existsInScope($definition->getSlug(), null);
    }

    /**
     * The template version a locked definition is compared with: the one it was cloned from, else the current one.
     */
    public function reference(WorkflowDefinition $definition): ?WorkflowDefinition
    {
        $slug    = $definition->getSlug();
        $version = $definition->getTemplateVersion();

        return ($version !== null ? $this->repository->findVersion($slug, null, $version) : null) ?? $this->repository->findCurrent($slug, null);
    }

    /**
     * @throws WorkflowStructureLockedException
     */
    public function assertAllowed(WorkflowDefinition $definition): void
    {
        if (!$this->isLocked($definition)) {
            return;
        }
        $template = $this->reference($definition);
        if (!$template instanceof WorkflowDefinition) {
            throw new WorkflowStructureLockedException($definition->getSlug(), 'its global template is gone');
        }
        if ($template->structure() !== $definition->structure()) {
            throw new WorkflowStructureLockedException($definition->getSlug());
        }
    }

    /**
     * Whether a stored tenant version breaks the lock (runtime check): locked, a template to compare with, and another
     * structure. Without any template there is nothing the tenant version could fall back to: not a violation here.
     */
    public function violates(WorkflowDefinition $definition): bool
    {
        if (!$this->isLocked($definition)) {
            return false;
        }
        $template = $this->reference($definition);

        return $template instanceof WorkflowDefinition && $template->structure() !== $definition->structure();
    }
}
