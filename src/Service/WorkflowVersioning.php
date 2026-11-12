<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;

use function max;
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
     * published version 1 with $publish. Refused when the tenant already has a definition with that slug (also when a
     * concurrent request created it first: the unique constraint decides, audit NO L3).
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
        try {
            $this->repository->save($copy);
        } catch (UniqueConstraintViolationException $exception) {
            throw new LogicException(sprintf('The tenant already has a workflow "%s".', $slug), 0, $exception);
        }
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
        $current = $this->repository->findDraft($slug, $tenant) ?? $this->repository->findCurrent($slug, $tenant);
        if (!$current instanceof WorkflowDefinition) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        return $this->createDraftOf($current);
    }

    /**
     * The open draft of the scope of this (already authorised) definition: the definition itself when it is a draft,
     * the existing draft of its scope, or a new draft copied from it (audit NO M1: the action works on the checked
     * entity, never on a re-query by slug and tenant). Two concurrent requests end with the same draft (L3).
     */
    public function createDraftOf(WorkflowDefinition $definition): WorkflowDefinition
    {
        if ($definition->isDraft()) {
            return $definition;
        }
        $slug   = $definition->getSlug();
        $tenant = $definition->getTenant();
        $draft  = $this->repository->findDraft($slug, $tenant);
        if ($draft instanceof WorkflowDefinition) {
            return $draft;
        }

        $draft = $definition->copyAsDraft($tenant, $this->repository->nextVersion($slug, $tenant));
        try {
            $this->repository->save($draft);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request created the same version first: that draft is the draft.
            return $this->repository->findDraft($slug, $tenant) ?? throw $exception;
        }

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

        return $this->publish($draft);
    }

    /**
     * Publishes this (already authorised) draft.
     *
     * @throws LogicException when the definition is not a draft
     */
    public function publish(WorkflowDefinition $draft): WorkflowDefinition
    {
        if (!$draft->isDraft()) {
            throw new LogicException(sprintf('Version %d of workflow "%s" is not a draft.', $draft->getVersion(), $draft->getSlug()));
        }

        $draft->publish();
        $this->repository->save($draft);
        $this->registry?->invalidate($draft->getSlug());

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
     * Deletes a draft, or every version of a published definition of this scope. A published definition is refused
     * while subjects are pinned to any of its versions (owner decision 2026-11-12): disable it instead, or remove the
     * subjects' pins first. A draft never runs, so it is never pinned.
     *
     * @throws WorkflowDefinitionInUseException when subjects are pinned to one of the versions
     */
    public function delete(WorkflowDefinition $definition): void
    {
        $versions = $definition->isDraft() ? [$definition] : $this->repository->findVersions($definition->getSlug(), $definition->getTenant());
        $ids      = [];
        foreach ($versions as $version) {
            if ($version->getId() !== null) {
                $ids[] = $version->getId();
            }
        }
        $pinned = $this->pins?->countForDefinitions($ids) ?? 0;
        if ($pinned > 0) {
            throw new WorkflowDefinitionInUseException($definition->getSlug(), $pinned);
        }

        foreach ($versions as $version) {
            // @igor-ignore - Schedules a delete in the private manager, flushed just below (no state kept).
            $this->repository->remove($version, false);
        }
        try {
            $this->repository->flush();
        } catch (ForeignKeyConstraintViolationException $exception) {
            // A subject was pinned between the count and the delete (audit NO-v N1): the pin foreign key is
            // ON DELETE RESTRICT, so the database refuses and nothing is deleted.
            throw new WorkflowDefinitionInUseException($definition->getSlug(), max(1, $this->pins?->countForDefinitions($ids) ?? 1), $exception);
        }
        $this->registry?->invalidate($definition->getSlug());
    }
}
