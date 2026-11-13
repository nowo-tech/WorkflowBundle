<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowInvalidShapeException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tenancy\CloneStructureLock;
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
    /**
     * @param bool $lockCloneStructure `tenancy.lock_clone_structure` (since 1.16): tenant clones keep the places and
     *                                 transitions of their global template, only guards and conditions are edited
     */
    private CloneStructureLock $lock;

    public function __construct(
        private WorkflowDefinitionRepository $repository,
        private ?DatabaseWorkflowRegistry $registry = null,
        private ?SubjectPinStore $pins = null,
        bool $lockCloneStructure = false,
    ) {
        $this->lock = new CloneStructureLock($repository, $lockCloneStructure);
    }

    /**
     * Copies the current published version of a global template into the tenant as a draft (version 1), or as a
     * published version 1 with $publish. Refused when the tenant already has a definition with that slug (also when a
     * concurrent request created it first: the unique constraint decides, audit NO L3), unless that clone is retired
     * (since 1.16): the copy is then its next version.
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
        $version = 1;
        if ($this->repository->existsInScope($slug, $tenant)) {
            // Since 1.16: a retired clone (no draft, no published version) can be customized again from the current
            // template, as its next version; its retired versions keep running their pinned subjects.
            if (!$this->isRetiredScope($slug, $tenant)) {
                throw new LogicException(sprintf('The tenant already has a workflow "%s".', $slug));
            }
            $version = $this->repository->nextVersion($slug, $tenant);
        }

        $copy = $template->copyAsDraft($tenant, $version, $template->getVersion());
        if ($publish) {
            // Audit OD M3: a published clone passes the lock like any other publish.
            $this->lock->assertAllowed($copy);
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
     * Publishes this (already authorised) draft. Since 1.16 the structure of a locked tenant clone must match its
     * template ({@see assertStructureAllowed()}); publishing a draft of a retired clone puts it back into service.
     *
     * @throws LogicException when the definition is not a draft
     * @throws WorkflowStructureLockedException
     * @throws WorkflowInvalidShapeException since 1.15.2, see {@see WorkflowShapeValidator}
     */
    public function publish(WorkflowDefinition $draft): WorkflowDefinition
    {
        if (!$draft->isDraft()) {
            throw new LogicException(sprintf('Version %d of workflow "%s" is not a draft.', $draft->getVersion(), $draft->getSlug()));
        }

        $this->assertStructureAllowed($draft);
        // Since 1.15.2: never publish a shape FrameworkBundle would refuse.
        $violations = WorkflowShapeValidator::violations($draft);
        if ($violations !== []) {
            throw new WorkflowInvalidShapeException($draft->getSlug(), $violations);
        }

        $draft->publish();
        $this->repository->save($draft);
        $this->registry?->invalidate($draft->getSlug());

        return $draft;
    }

    /**
     * Whether `tenancy.lock_clone_structure` is on (since 1.16).
     */
    public function isLockEnabled(): bool
    {
        return $this->lock->enabled();
    }

    /**
     * Whether the places and transitions of this definition are locked to its global template (since 1.16), see
     * {@see CloneStructureLock}. Global definitions are never locked.
     */
    public function isStructureLocked(WorkflowDefinition $definition): bool
    {
        return $this->lock->isLocked($definition);
    }

    /**
     * Refuses a locked definition whose structure (initial place, place names, transitions with their `from` / `to`
     * places) differs from the template version it was cloned from (the current template when that version is gone
     * or it is not a clone). Labels, guards, conditions and the automatic flag may differ.
     *
     * @throws WorkflowStructureLockedException
     */
    public function assertStructureAllowed(WorkflowDefinition $definition): void
    {
        $this->lock->assertAllowed($definition);
    }

    /**
     * Retires a tenant clone (since 1.16): every published version of its scope becomes `retired`. New subjects of the
     * tenant run the global template again; subjects pinned to a retired version keep running it (pins are untouched,
     * and the clone still cannot be deleted while they exist, owner decision 2026-11-12). Publishing a new draft of the
     * scope, {@see reinstate()} or {@see cloneTemplate()} puts a clone back into service.
     *
     * @throws LogicException when the definition is global, has no published version, or there is no enabled global
     *                        template of the slug to fall back to
     *
     * @return WorkflowDefinition the newest retired version
     */
    public function retire(WorkflowDefinition $definition): WorkflowDefinition
    {
        $slug   = $definition->getSlug();
        $tenant = $definition->getTenant();
        if (!$tenant instanceof WorkflowTenant) {
            throw new LogicException(sprintf('Workflow "%s" is a global template: disable it instead of retiring it.', $slug));
        }
        $template = $this->repository->findCurrent($slug, null);
        if (!$template instanceof WorkflowDefinition || !$template->isEnabled()) {
            throw new LogicException(sprintf('Workflow "%s" cannot be retired: there is no enabled global template for new subjects.', $slug));
        }

        $retired = $this->changeStatus($slug, $tenant, static fn (WorkflowDefinition $version): bool => $version->isPublished(), static fn (WorkflowDefinition $version): WorkflowDefinition => $version->retire());

        return $retired ?? throw new LogicException(sprintf('Workflow "%s" has no published version to retire.', $slug));
    }

    /**
     * Puts a retired tenant clone back into service (since 1.16): its retired versions are published again and the
     * newest one is the version new subjects start with.
     *
     * @throws LogicException when the scope has no retired version
     * @throws WorkflowStructureLockedException when the lock is on and the newest retired version breaks it
     *
     * @return WorkflowDefinition the newest reinstated version
     */
    public function reinstate(WorkflowDefinition $definition): WorkflowDefinition
    {
        $slug = $definition->getSlug();
        // Audit OD M3: the version that runs again passes the lock (it may predate it).
        $retired = $this->repository->findRetired($slug, $definition->getTenant());
        if ($retired instanceof WorkflowDefinition) {
            $this->lock->assertAllowed($retired);
        }
        $version = $this->changeStatus($slug, $definition->getTenant(), static fn (WorkflowDefinition $version): bool => $version->isRetired(), static fn (WorkflowDefinition $version): WorkflowDefinition => $version->reinstate());

        return $version ?? throw new LogicException(sprintf('Workflow "%s" has no retired version.', $slug));
    }

    /**
     * Whether every non-draft version of the scope is retired and there is no draft (since 1.16).
     */
    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        $retired = false;
        foreach ($this->repository->findVersions($slug, $tenant) as $version) {
            if (!$version->isRetired()) {
                return false;
            }
            $retired = true;
        }

        return $retired;
    }

    /**
     * Applies $change to every version of the scope $select picks, flushes once and returns the newest changed one.
     *
     * @param callable(WorkflowDefinition): bool $select
     * @param callable(WorkflowDefinition): WorkflowDefinition $change
     */
    private function changeStatus(string $slug, ?WorkflowTenant $tenant, callable $select, callable $change): ?WorkflowDefinition
    {
        $newest = null;
        foreach ($this->repository->findVersions($slug, $tenant) as $version) {
            if (!$select($version)) {
                continue;
            }
            $change($version);
            // @igor-ignore - Schedules the update in the private manager, flushed just below (no state kept).
            $this->repository->save($version, false);
            $newest ??= $version;
        }
        if ($newest === null) {
            return null;
        }
        $this->repository->flush();
        $this->registry?->invalidate($slug);

        return $newest;
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
