<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use LogicException;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowInvalidShapeException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;

/**
 * Framework-free versioning port of the database workflows (since 1.19, REQ-ARCH-001 rule 1): drafts, publishing,
 * retiring and template clones of tenant-scoped definitions with scalars, bundle enums and bundle value objects only.
 * It is the twin of {@see WorkflowVersioningInterface}, which takes and returns the `WorkflowDefinition`
 * aggregate — a bundle-owned entity, but one that exposes Doctrine `Collection`s, so a framework type is reachable
 * through it. This port takes a {@see WorkflowDefinitionRef} and returns a {@see WorkflowDefinitionView} instead, and
 * is what a DDD / hexagonal host calls.
 *
 * {@see WorkflowVersionManager} is the built-in implementation: a facade over
 * {@see WorkflowVersioningInterface}, so a host decorator or replacement of that SPI is honoured here as well (the
 * same split as {@see WorkflowRuntimeInterface} over {@see WorkflowRegistryInterface} since 1.15). Behaviour is still
 * replaced on {@see WorkflowVersioningInterface}, which the admin UI keeps calling with the entity it authorised
 * (audit NO M1); this port may also be decorated, which then applies to the hosts calling it.
 *
 * Every write goes through the bundle's private entity manager, never through an application manager
 * (REQ-ARCH-001 rule 6), exactly as on the deprecated port. The reads below are the entity-free way to obtain a
 * reference in the first place; nothing is memoised, every call reads the store (REQ-RUNTIME-002).
 */
interface WorkflowVersionManagerInterface
{
    /**
     * The version new subjects of this scope start with (the current published one), or null when the scope has none.
     */
    public function currentVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView;

    /**
     * The open draft of this scope, or null when there is none.
     */
    public function draftVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView;

    /**
     * Every version of the slug in exactly this scope, newest first.
     *
     * @return list<WorkflowDefinitionView>
     */
    public function versions(string $slug, ?WorkflowTenant $tenant): array;

    /**
     * Copies the current published version of a global template into the tenant as a draft (version 1), or as a
     * published version 1 with $publish. Refused when the tenant already has a definition with that slug, unless that
     * clone is retired: the copy is then its next version.
     *
     * @throws WorkflowNotFoundException when there is no published global template with that slug
     * @throws LogicException when the tenant already has that slug
     */
    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinitionView;

    /**
     * The open draft of a definition of this scope, created from its current published version when there is none.
     *
     * @throws WorkflowNotFoundException when the scope has no version of that slug
     */
    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView;

    /**
     * The open draft of the scope of this version: the version itself when it is a draft, the existing draft of its
     * scope, or a new draft copied from it. Two concurrent requests end with the same draft.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     */
    public function createDraftOf(WorkflowDefinitionRef $definition): WorkflowDefinitionView;

    /**
     * Publishes the open draft of a definition of this scope: new subjects start with it, subjects already running
     * keep their pinned version.
     *
     * @throws WorkflowNotFoundException when the scope has no draft of that slug
     */
    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView;

    /**
     * Publishes this draft. The structure of a locked tenant clone must match its template
     * ({@see self::assertStructureAllowed()}); publishing a draft of a retired clone puts it back into service.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     * @throws LogicException when the version is not a draft
     * @throws WorkflowStructureLockedException
     * @throws WorkflowInvalidShapeException
     */
    public function publish(WorkflowDefinitionRef $draft): WorkflowDefinitionView;

    /**
     * Whether `tenancy.lock_clone_structure` is on.
     */
    public function isLockEnabled(): bool;

    /**
     * Whether the places and transitions of this version are locked to its global template. Global definitions are
     * never locked.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     */
    public function isStructureLocked(WorkflowDefinitionRef $definition): bool;

    /**
     * Refuses a locked version whose structure (initial place, place names, transitions with their `from` / `to`
     * places) differs from the template version it was cloned from. Labels, guards, conditions and the automatic flag
     * may differ.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     * @throws WorkflowStructureLockedException
     */
    public function assertStructureAllowed(WorkflowDefinitionRef $definition): void;

    /**
     * Retires a tenant clone: every published version of its scope becomes `retired`. New subjects of the tenant run
     * the global template again; subjects pinned to a retired version keep running it.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     * @throws LogicException when the version is global, has no published version, or there is no enabled global
     *                        template of the slug to fall back to
     *
     * @return WorkflowDefinitionView the newest retired version
     */
    public function retire(WorkflowDefinitionRef $definition): WorkflowDefinitionView;

    /**
     * Puts a retired tenant clone back into service: its retired versions are published again and the newest one is
     * the version new subjects start with.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     * @throws LogicException when the scope has no retired version
     * @throws WorkflowStructureLockedException when the lock is on and the newest retired version breaks it
     *
     * @return WorkflowDefinitionView the newest reinstated version
     */
    public function reinstate(WorkflowDefinitionRef $definition): WorkflowDefinitionView;

    /**
     * Whether every non-draft version of the scope is retired and there is no draft.
     */
    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool;

    /**
     * Removes the version pins of a subject in every workflow, e.g. when the host deletes or anonymises the subject.
     *
     * @return int pins removed
     */
    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int;

    /**
     * Deletes a draft, or every version of a published definition of this scope. A published definition is refused
     * while subjects are pinned to any of its versions (owner decision 2026-11-12): disable it instead, or remove the
     * subjects' pins first. A draft never runs, so it is never pinned.
     *
     * @throws WorkflowNotFoundException when the reference names no stored version
     * @throws WorkflowDefinitionInUseException when subjects are pinned to one of the versions
     */
    public function delete(WorkflowDefinitionRef $definition): void;
}
