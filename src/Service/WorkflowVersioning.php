<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;

use function sprintf;

/**
 * Drafts, publishing and template clones of tenant-scoped definitions (since 1.15). Used by the admin UI; hosts call
 * it too, e.g. to give a new organization its own copy of a global template.
 *
 * Versioning model (docs/MULTITENANCY.md, "Versions"): a published version is never edited. Edits go to a draft (the
 * next version number of the same tenant and slug); publishing the draft makes it the version new subjects start
 * with, while subjects already running keep the version they are pinned to. Writes go through the bundle's private
 * entity manager (the repository), never through an application manager.
 */
final readonly class WorkflowVersioning
{
    public function __construct(
        private WorkflowDefinitionRepository $repository,
        private ?DatabaseWorkflowRegistry $registry = null,
        private ?SubjectPinStore $pins = null,
    ) {
    }

    /**
     * Copies the current published version of a global template into the tenant as a draft (version 1), or as a
     * published version 1 with $publish. Refused when the tenant already has a definition with that slug.
     *
     * @throws WorkflowNotFoundException when there is no published global template with that slug
     * @throws LogicException when the tenant already has that slug
     */
    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinition
    {
        $template = $this->repository->findCurrent($slug, null);
        if (!$template instanceof WorkflowDefinition) {
            throw WorkflowNotFoundException::forSlug($slug);
        }
        if ($this->repository->existsInScope($slug, $tenant)) {
            throw new LogicException(sprintf('The tenant already has a workflow "%s".', $slug));
        }

        $copy = $template->copyAsDraft($tenant, 1, $template->getVersion());
        if ($publish) {
            $copy->publish();
        }
        $this->repository->save($copy);
        $this->registry?->invalidate($slug);

        return $copy;
    }

    /**
     * The open draft of a definition of this scope, created from its current published version when there is none.
     *
     * @throws WorkflowNotFoundException when the scope has no version of that slug
     */
    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $draft = $this->repository->findDraft($slug, $tenant);
        if ($draft instanceof WorkflowDefinition) {
            return $draft;
        }

        $current = $this->repository->findCurrent($slug, $tenant);
        if (!$current instanceof WorkflowDefinition) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        $draft = $current->copyAsDraft($tenant, $this->repository->nextVersion($slug, $tenant));
        $this->repository->save($draft);

        return $draft;
    }

    /**
     * Publishes the open draft of a definition of this scope: new subjects start with it, subjects already running
     * keep their pinned version.
     *
     * @throws WorkflowNotFoundException when the scope has no draft of that slug
     */
    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $draft = $this->repository->findDraft($slug, $tenant);
        if (!$draft instanceof WorkflowDefinition) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        $draft->publish();
        $this->repository->save($draft);
        $this->registry?->invalidate($slug);

        return $draft;
    }

    /**
     * Removes the version pins of a subject in every workflow (since 1.15), e.g. when the host deletes or anonymises
     * the subject: the bundle stores no actor id, only this business reference (docs/MULTITENANCY.md, "GDPR").
     *
     * @return int pins removed
     */
    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        return $this->pins?->forget($subject, $tenant) ?? 0;
    }

    /**
     * Deletes a draft, or every version of a published definition of this scope (subjects pinned to one of them lose
     * their pin and follow the scope's current version, else the global template).
     */
    public function delete(WorkflowDefinition $definition): void
    {
        $versions = $definition->isDraft() ? [$definition] : $this->repository->findVersions($definition->getSlug(), $definition->getTenant());
        foreach ($versions as $version) {
            if ($version->getId() !== null) {
                $this->pins?->forgetDefinition($version->getId());
            }
            // @igor-ignore - Schedules a delete in the private manager, flushed just below (no state kept).
            $this->repository->remove($version, false);
        }
        $this->repository->flush();
        $this->registry?->invalidate($definition->getSlug());
    }
}
