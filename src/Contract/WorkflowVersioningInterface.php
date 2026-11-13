<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowInvalidShapeException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowShapeValidator;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tenancy\CloneStructureLock;

/**
 * Drafts, publishing, retiring and template clones of tenant-scoped definitions (since 1.17, REQ-ARCH-001 rule 1):
 * the port hosts call (e.g. to give a new organization its own copy of a global template) and may decorate or
 * replace; {@see WorkflowVersioning} is the built-in implementation and the admin UI calls this interface.
 *
 * No Symfony, Doctrine or HTTP type in the signatures: the methods take slugs, bundle VOs ({@see WorkflowTenant},
 * {@see WorkflowSubjectRef}) or the bundle's own definition aggregate ({@see WorkflowDefinition}). That aggregate is a
 * bundle-owned entity, never a host entity, but it exposes Doctrine `Collection`s (places, transitions, match rules),
 * so a framework type is reachable through this port (REQ-ARCH-001 rule 1 ⚠️): id / slug-based forms returning VOs
 * are 2.0 work (UPGRADING "To 1.17.0"). A replacement must keep the owner rule of
 * {@see self::delete()}: a published definition is not deleted while subjects are pinned to it (the pin foreign key is
 * ON DELETE RESTRICT, so the database refuses it as well). See {@see CloneStructureLock} and
 * {@see WorkflowShapeValidator} for the checks of {@see self::publish()}.
 */
interface WorkflowVersioningInterface
{
    /**
     * Copies the current published version of a global template into the tenant as a draft (version 1), or as a
     * published version 1 with $publish. Refused when the tenant already has a definition with that slug (also when a
     * concurrent request created it first: the unique constraint decides, audit NO L3), unless that clone is retired
     * (since 1.16): the copy is then its next version.
     *
     * @throws WorkflowNotFoundException when there is no published global template with that slug
     * @throws LogicException when the tenant already has that slug
     */
    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinition;

    /**
     * The open draft of a definition of this scope, created from its current published version when there is none.
     *
     * @throws WorkflowNotFoundException when the scope has no version of that slug
     */
    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition;

    /**
     * The open draft of the scope of this (already authorised) definition: the definition itself when it is a draft,
     * the existing draft of its scope, or a new draft copied from it (audit NO M1: the action works on the checked
     * entity, never on a re-query by slug and tenant). Two concurrent requests end with the same draft (L3).
     */
    public function createDraftOf(WorkflowDefinition $definition): WorkflowDefinition;

    /**
     * Publishes the open draft of a definition of this scope: new subjects start with it, subjects already running
     * keep their pinned version.
     *
     * @throws WorkflowNotFoundException when the scope has no draft of that slug
     */
    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition;

    /**
     * Publishes this (already authorised) draft. Since 1.16 the structure of a locked tenant clone must match its
     * template ({@see self::assertStructureAllowed()}); publishing a draft of a retired clone puts it back into service.
     *
     * @throws LogicException when the definition is not a draft
     * @throws WorkflowStructureLockedException
     * @throws WorkflowInvalidShapeException since 1.15.2, see {@see WorkflowShapeValidator}
     */
    public function publish(WorkflowDefinition $draft): WorkflowDefinition;

    /**
     * Whether `tenancy.lock_clone_structure` is on (since 1.16).
     */
    public function isLockEnabled(): bool;

    /**
     * Whether the places and transitions of this definition are locked to its global template (since 1.16), see
     * {@see CloneStructureLock}. Global definitions are never locked.
     */
    public function isStructureLocked(WorkflowDefinition $definition): bool;

    /**
     * Refuses a locked definition whose structure (initial place, place names, transitions with their `from` / `to`
     * places) differs from the template version it was cloned from (the current template when that version is gone
     * or it is not a clone). Labels, guards, conditions and the automatic flag may differ.
     *
     * @throws WorkflowStructureLockedException
     */
    public function assertStructureAllowed(WorkflowDefinition $definition): void;

    /**
     * Retires a tenant clone (since 1.16): every published version of its scope becomes `retired`. New subjects of the
     * tenant run the global template again; subjects pinned to a retired version keep running it (pins are untouched,
     * and the clone still cannot be deleted while they exist, owner decision 2026-11-12). Publishing a new draft of the
     * scope, {@see self::reinstate()} or {@see self::cloneTemplate()} puts a clone back into service.
     *
     * @throws LogicException when the definition is global, has no published version, or there is no enabled global
     *                        template of the slug to fall back to
     *
     * @return WorkflowDefinition the newest retired version
     */
    public function retire(WorkflowDefinition $definition): WorkflowDefinition;

    /**
     * Puts a retired tenant clone back into service (since 1.16): its retired versions are published again and the
     * newest one is the version new subjects start with.
     *
     * @throws LogicException when the scope has no retired version
     * @throws WorkflowStructureLockedException when the lock is on and the newest retired version breaks it
     *
     * @return WorkflowDefinition the newest reinstated version
     */
    public function reinstate(WorkflowDefinition $definition): WorkflowDefinition;

    /**
     * Whether every non-draft version of the scope is retired and there is no draft (since 1.16).
     */
    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool;

    /**
     * Removes the version pins of a subject in every workflow (since 1.15), e.g. when the host deletes or anonymises
     * the subject: the bundle stores no actor id, only this business reference (docs/MULTITENANCY.md, "GDPR").
     *
     * @return int pins removed
     */
    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int;

    /**
     * Deletes a draft, or every version of a published definition of this scope. A published definition is refused
     * while subjects are pinned to any of its versions (owner decision 2026-11-12): disable it instead, or remove the
     * subjects' pins first. A draft never runs, so it is never pinned.
     *
     * @throws WorkflowDefinitionInUseException when subjects are pinned to one of the versions
     */
    public function delete(WorkflowDefinition $definition): void;
}
