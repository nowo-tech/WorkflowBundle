# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.17.0](#to-1170)
- [To 1.17.0](#to-1170-1)
- [To 1.16.0](#to-1160)
  - [To 1.16.0](#to-1160-1)
- [To 1.15.2](#to-1152)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
  - [Workflow UI access port](#workflow-ui-access-port)
  - [flush_after_apply](#flush_after_apply)
  - [Private entity manager for the definitions](#private-entity-manager-for-the-definitions)
  - [XML mapping](#xml-mapping)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.2](#to-1112)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Template contract](#template-contract)
  - [Configuration](#configuration)
- [From 1.8.1 to 1.8.2](#from-181-to-182)
- [From 1.8.0 to 1.8.1](#from-180-to-181)
- [From 1.7.0 to 1.8.0](#from-170-to-180)
- [From 1.6.12 to 1.7.0](#from-1612-to-170)
- [From 1.6.11 to 1.6.12](#from-1611-to-1612)
- [From 1.6.10 to 1.6.11](#from-1610-to-1611)
- [From 1.6.9 to 1.6.10](#from-169-to-1610)
- [From 1.6.7 to 1.6.8](#from-167-to-168)
- [From 1.6.6 to 1.6.7](#from-166-to-167)
- [From 1.6.5 to 1.6.6](#from-165-to-166)
- [General process](#general-process)
- [Upgrading to 1.6.5](#upgrading-to-165)
- [Upgrading to 1.6.4](#upgrading-to-164)
- [Upgrading to 1.6.3](#upgrading-to-163)
- [Upgrading to 1.6.2](#upgrading-to-162)
- [Upgrading to 1.6.1](#upgrading-to-161)
- [Upgrading to 1.6.0](#upgrading-to-160)
- [Upgrading to 1.5.2](#upgrading-to-152)
- [Upgrading to 1.5.1](#upgrading-to-151)
- [Upgrading to 1.5.0](#upgrading-to-150)
  - [Breaking — Web UI private by default (REQ-UI-002)](#breaking--web-ui-private-by-default-req-ui-002)
  - [Web UI look-and-feel (REQ-UI-001)](#web-ui-look-and-feel-req-ui-001)
  - [Twig blocks](#twig-blocks)
  - [Recipe / demo](#recipe--demo)
- [Upgrading to 1.4.3](#upgrading-to-143)
- [Upgrading to 1.4.2](#upgrading-to-142)
- [Upgrading to 1.4.1](#upgrading-to-141)
- [Upgrading to 1.4.0](#upgrading-to-140)
- [Upgrading to 1.3.0](#upgrading-to-130)
  - [Optional: role-based CRUD UI protection](#optional-role-based-crud-ui-protection)
  - [Optional: enable new UI locales](#optional-enable-new-ui-locales)
- [Upgrading to 1.2.0](#upgrading-to-120)
  - [Breaking: PHP and Symfony](#breaking-php-and-symfony)
- [Upgrading to 1.1.0](#upgrading-to-110)
  - [Optional: protect the CRUD UI](#optional-protect-the-crud-ui)
  - [Optional: custom table prefix](#optional-custom-table-prefix)
  - [Translation overrides](#translation-overrides)
  - [Custom registry or tests](#custom-registry-or-tests)
- [Upgrading to 1.0.0](#upgrading-to-100)
  - [Translation domain](#translation-domain)
  - [Twig templates](#twig-templates)
- [Compatibility](#compatibility)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
- [Unreleased](#unreleased-1)
- [To 1.6.10](#to-1610)
- [To 1.6.9](#to-169)
- [To 1.6.0](#to-160)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## Unreleased

## To 1.17.0

From **1.16.0**:

```bash
composer update nowo-tech/workflow-bundle
```

## To 1.17.0

From **1.16.x** (next minor, unreleased): no migration, no configuration change, nothing to do.

```bash
composer update nowo-tech/workflow-bundle
```

- **New ports (REQ-ARCH-001 rule 1).** Type-hint `Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface` instead
  of `Service\WorkflowVersioning`, and `Contract\WorkflowSlugResolverInterface` when you only need the slug a subject
  runs (`resolveSlugForSubject()`), instead of `Service\WorkflowResolver`. The class ids keep working and return the
  same objects.
- **Decorators.** The admin UI controller now receives `WorkflowVersioningInterface`. If you decorate or replace it,
  the UI actions (clone, publish, retire, reinstate, delete) go through your service; keep refusing `delete()` while
  subjects are pinned (`WorkflowDefinitionInUseException`).
- **Deferred to 2.0** (needs a major, see [ARCHITECTURE.md](ARCHITECTURE.md#deferred-to-20)): the
  `runtime.flush_after_apply` default becomes `false` (REQ-ARCH-001 rule 6; set it explicitly now to silence the 1.14
  deprecation), and the deprecated `WorkflowRegistryInterface` / `WorkflowUiAccessCheckerInterface` ports are removed.
- **2.0 work on `WorkflowVersioningInterface` (REQ-ARCH-001 rule 1 stays ⚠️ until then).** In 1.17 it takes and
  returns the bundle's `WorkflowDefinition` entity, through which Doctrine `Collection`s are reachable. 2.0 adds or
  switches to id / slug-based forms that return VOs, e.g. `publishById(int $definitionId)`, `retireById()`,
  `reinstateById()`, `deleteById()`, `createDraftOfId()`, and `cloneTemplate()` / `createDraft()` / `publishDraft()`
  returning a definition VO (id, slug, version, tenant, status) instead of the entity.
- **Slug resolver scope.** Replacing `WorkflowSlugResolverInterface` changes only what your own callers get: the
  bundle's applicator, runtime and admin UI still use `Service\WorkflowResolver` directly.

## To 1.16.0

From **1.15.2**:

```bash
composer update nowo-tech/workflow-bundle
```

### To 1.16.0

From **1.15.x** (minor):

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
php bin/console doctrine:migrations:diff   # four nullable columns
```

- **⚠ Invalid transition shapes are now a hard failure.** Run `php bin/console nowo:workflow:lint` on 1.15.2 first:
  every definition it lists (a transition without `from` or `to` places, a `state_machine` transition with several
  `to` places) **fails to build in 1.16.0** with an `InvalidArgumentException`, as FrameworkBundle refuses such a
  `framework.workflows` entry. Split such a transition into one transition per target (publish a new draft for a tenant
  version; a version subjects are pinned to keeps failing until they move or are forgotten).
- **Locked clones at runtime:** with `tenancy.lock_clone_structure`, a tenant version whose structure differs from its
  template is no longer used for new subjects (they run the template; pinned subjects keep it). A new global template
  cannot take a slug a tenant already uses.
- **Label fields:** the editor accepts identifier keys / domains only, and the domains of `tenancy.label_domains`
  (default `[messages, workflows]`): add your own domains there.
- **Schema:** `workflow_place` and `workflow_transition` get `label_key` (string 255, nullable) and `label_domain`
  (string 128, nullable). Generate and run a migration (or `nowo:workflow:sync-schema`). Hosts mapping the bundle
  entities from the shipped XML get them too; a host with its own mapping of `WorkflowPlace` / `WorkflowTransition`
  adds both fields.
- **`WorkflowRuntimeInterface::pin()`** is a new interface method. Hosts that only *call* the runtime port have
  nothing to do. A host that *implements* the interface itself (its own runtime service aliased to it) must add
  `pin(object $subject, string $workflowSlug, WorkflowPinMode $mode = WorkflowPinMode::KeepExisting):
  WorkflowPinResult`, or decorate the bundle's `Service\WorkflowRuntime`.
- **`WorkflowDefinitionStatus::Retired`** is a new enum case: a `match` over the status in host code must handle it.
  `WorkflowDefinitionRepository::findCurrent()` no longer returns a retired version (a retired clone has no current
  version); `findRetired()` and `findVersion()` are new.
- **Locked clones** are opt-in: `nowo_workflow.tenancy.lock_clone_structure: true`. Enable it before tenants
  customize; a clone whose structure already differs from its template cannot be published while it is on.
- Templates: `edit.html.twig` receives `structure_locked` and
  `_collection_section.html.twig` an optional `locked`; overridden copies keep working (both default to false).

## To 1.15.2

From **1.15.1**:

```bash
composer update nowo-tech/workflow-bundle
```

From **1.15.1** (patch, no configuration change):

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
php bin/console doctrine:migrations:diff   # MySQL / MariaDB only, see below
```

- **MySQL / MariaDB:** the key columns (`tenant_key`, `slug`, `workflow_slug`, `subject_ref`) move to a NO PAD binary
  collation: `utf8mb4_0900_bin` on MySQL 8.0.17+, `utf8mb4_nopad_bin` on MariaDB (MySQL < 8.0.17 keeps `utf8mb4_bin`).
  Generate one migration: it alters those five columns once (`CHARACTER SET utf8mb4 COLLATE …`); afterwards the diff
  is empty. Set `doctrine.dbal.server_version` (or `?serverVersion=` in the URL) with the full version (`8.4.3`,
  `9.7`, `11.4.4-MariaDB`); `8.0` without a patch number is read as `8.0.0` and keeps `utf8mb4_bin`. PostgreSQL and
  SQLite: nothing changes.
- **DoctrineBundle 3 hosts:** the collation and `table_prefix` now apply (1.15.0 / 1.15.1 ignored them there). If you
  added the collation by hand in a migration, the diff no longer proposes to drop it.
- **⚠ DoctrineBundle 3 hosts with a custom `table_prefix` — back up first.** Your tables were created **without** the
  prefix (`workflow_definition`, `workflow_place`, …). Since 1.15.2 schema generation stops with an error that lists
  the statements to run, for example (MySQL / MariaDB; PostgreSQL / SQLite get `ALTER TABLE … RENAME TO …`):

  ```text
  nowo_workflow.table_prefix is "acme_", but the Workflow tables still have their default names (created while
  DoctrineBundle 3 ignored the prefix, WorkflowBundle 1.15.0 / 1.15.1). A generated migration would DROP them and
  create empty ones. Back up the database, rename them first, then generate the migration again:
  RENAME TABLE workflow_definition TO acme_definition;
  RENAME TABLE workflow_place TO acme_place;
  RENAME TABLE workflow_transition TO acme_transition;
  RENAME TABLE workflow_match_rule TO acme_match_rule;
  RENAME TABLE workflow_subject_pin TO acme_subject_pin;
  ```

  Put those statements in a hand-written migration, run it, then run `doctrine:migrations:diff` again: it should only
  contain index renames and the collation changes. **Never apply a generated `DROP TABLE` of the bundle tables.** Or
  remove `table_prefix` to keep the unprefixed names. Hosts on DoctrineBundle 2, and hosts without `table_prefix`, are
  not affected.
- `TablePrefixSubscriber` and `BinaryKeyCollationSubscriber` no longer implement `Doctrine\Common\EventSubscriber`
  (no `getSubscribedEvents()`); they are tagged `doctrine.event_listener`. If you registered them on an event manager
  yourself, use `addEventListener(Events::loadClassMetadata, …)` / `addEventListener(ToolEvents::postGenerateSchemaTable, …)`.
- **State machines:** a transition with several `from` places now fires from any of them (FrameworkBundle semantics).
- **Invalid transition shapes:** run `php bin/console nowo:workflow:lint`. It lists every stored transition without
  `from` or `to` places and every `state_machine` transition with several `to` places. In 1.15.2 they still run as in
  1.15.1 (deprecation + warning), the editor and `publish()` refuse them, and **1.16.0 refuses to build them**: split
  them into one transition per target before upgrading to 1.16.0.
- **MySQL:** a `server_version` of `8` or `8.0` (no patch number) keeps the PAD SPACE `utf8mb4_bin` and logs a
  warning; configure the full version (`8.0.40`). `8.4`, `9.7` and full versions are fine.

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/workflow-bundle
```

- The bundle's private entity manager has its own repository factory. A `repository_factory` you configured on the
  Doctrine entity manager is used by it only for service repositories (repositories implementing
  `ServiceEntityRepositoryInterface`); every other repository of the private manager is built by the bundle, from the
  mapped `repositoryClass` or your `default_repository_class`.

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/workflow-bundle
```

**What changes for you:**

1. **Update the database schema** (required for every application): new columns on `workflow_definition` and
   `workflow_transition`, a new unique constraint and a new table `workflow_subject_pin`. Generate a migration
   (`php bin/console doctrine:migrations:diff`) or run `php bin/console nowo:workflow:sync-schema` (it drops `uniq_workflow_definition_slug` and creates `uniq_workflow_definition_scope`). Existing
   rows become global (`tenant_key ''`), version 1, published: nothing else changes for them.
2. **Nothing else, unless you opt in.** Without `nowo_workflow.tenancy.tenant_resolver` (and without the guard ports)
   the runtime, the events and the UI behave as in 1.14 (tested: same workflow events in the same order, same
   markings, and the tenancy-only services are not registered).
3. **If you replaced or decorated `WorkflowRegistryInterface`**, you get a deprecation when the container is built:
   it keeps working until 2.0. Call the new framework-free `WorkflowRuntimeInterface` from your code instead (it
   returns `WorkflowTransitionResult` / `WorkflowMarkingView`, and reports a blocked transition in the result instead
   of throwing). We deprecated the interface rather than marking it `@internal`: it was documented as the replaceable
   port, so hosts get a full minor cycle with a build-time notice; until 2.0 it stays the SPI the applicator asks. A
   host registry cannot be combined with tenancy (the build fails: the runtime builds tenant versions itself).

To enable multitenancy (organizations, templates, versions, guards, audit) follow
[MULTITENANCY.md](MULTITENANCY.md); the short version:

```yaml
nowo_workflow:
    web_ui:
        path_prefix: '/organizations/{uuid}/settings/workflows'
    tenancy:
        tenant_resolver: App\Workflow\WorkflowTenancy            # WorkflowTenantResolverInterface
        subject_reference_resolver: App\Workflow\WorkflowTenancy # pins in-flight subjects to their version
        subject_classes:                                          # what tenants may create workflows for
            App\Leave\LeaveRequest: status
        ui:
            tenant_resolver: App\Workflow\WorkflowTenancy        # required with the UI
            action_checker: App\Workflow\WorkflowTenancy         # required with the UI
    guards:
        permission_checker: App\Workflow\WorkflowTenancy
        variables_provider: App\Workflow\WorkflowTenancy
```

On **MySQL / MariaDB** the migration also switches `tenant_key`, `slug`, `workflow_slug` and `subject_ref` to the
binary collation `utf8mb4_bin` (exact keys, audit NO M1 / NO-v), and the version-pin foreign key is
`ON DELETE RESTRICT`. The collation is added when the schema is generated, so the database (or
`doctrine.dbal.server_version`, recommended) must be reachable when you generate the migration. Tenant keys and subject references must be printable ASCII without
spaces (UUIDs, slugs): other values are refused by the value objects.

A long-running process that runs console commands in-process (or consumes messages) no longer loses the console
boundary of the bundle's private entity manager when another console listener throws or stops the propagation (audit
NM); nothing to change.

When you turn tenancy on, your existing definitions become the global templates (published version 1); the editor
then edits drafts and "Publish" makes them run. A published definition that running subjects are pinned to cannot be
deleted (disable it instead). Other 1.15 notes:

- the internal controller actions take the `Request` (only code that calls them directly is affected);
- built workflows carry three more workflow metadata keys (`nowo_definition_*`) and the guard settings as transition
  metadata;
- `WorkflowDefinitionRepository::findOneBySlug()` returns the global, current published version (the only one in
  1.14 data).

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/workflow-bundle
```

**What changes for you:** nothing to change in your code or configuration. Three edge cases of 1.14.0 behave better:

- if a Doctrine mapping driver chain cannot be inspected when the container is built (a custom chain service), the
  rollback middleware of the private entity manager is now on the connection of every manager the runtime rule may
  pick, not only on the default one. A rollback on one of those connections may replace the private manager once
  more than needed; nothing else changes;
- a Doctrine `onClear` listener of yours that throws no longer breaks the end of a request, message or command: the
  failure is logged as a warning;
- a decorator of the deprecated `WorkflowUiAccessCheckerInterface` registered with `on_invalid: ignore` no longer fails
  the build when your `access_checker` implements only `WorkflowUiAccessPolicyInterface`: Symfony drops it, as that
  flag asks. Without the flag, the build still fails (migrate the decorator to `WorkflowUiAccessPolicyInterface`).

## To 1.14.0

From **1.13.0**:

```bash
composer update nowo-tech/workflow-bundle
```

Every 1.13 **configuration** keeps its behaviour, but **one code pattern changes silently**, check it first:

> **Do you edit workflow definitions in your own code?** If your code loads a definition through
> `WorkflowDefinitionRepository` or `$entityManager->getRepository(WorkflowDefinition::class)` (both return the
> bundle's repository), changes it and then calls `$entityManager->flush()` on **your** manager, 1.14 **persists
> nothing and raises no error**: the definition is managed by the bundle's private entity manager. Call
> `$repository->save($definition)` (or `remove()`) instead. Likewise, one of your entities that references a
> definition loaded through the repository now fails your flush with Doctrine's *"A new entity was found through the
> relationship…"*: reference it by slug or id, or load it with your own manager's query builder. As a safety net,
> changes still pending in the private manager when a request, message or command ends are dropped and logged as a
> warning (entity class and count). See [Private entity manager for the definitions](#private-entity-manager-for-the-definitions).

Deprecations may appear when the container is built; each one says what to change before 2.0.

### Workflow UI access port

`Contract\WorkflowUiAccessCheckerInterface::isGranted(Request $request)` is **deprecated** (REQ-ARCH-001 rule 3:
no Symfony `Request` in a public port) and removed in 2.0. Its framework-free twin is
`Contract\WorkflowUiAccessPolicyInterface::allows(Model\WorkflowUiAccessCheck $check)`; the VO carries the method,
route name, controller, path, scalar route parameters and a `Model\WorkflowActor` (identifier, token role names,
impersonator; the user object is reachable through `user()` but never dumped).

| Your 1.13 setup | 1.14 behaviour |
|-----------------|----------------|
| No `access_checker` (role-based, allow-all, deny-all) | Same decisions, through the new port. |
| `access_checker` implementing only the deprecated interface | Asked exactly as in 1.13; deprecation at build time. |
| `access_checker` implementing neither interface | **The build fails** (1.13 failed with a `TypeError` on the first request). |
| Service whose class is unknown at build time (factory) | Asked as in 1.13 through the deprecated port, no deprecation. |
| A decorator of `WorkflowUiAccessCheckerInterface` | Keeps running: the deprecated port stays the one asked. |
| You alias `WorkflowUiAccessPolicyInterface` to your service | Your policy is asked; with a decorator of the deprecated port too, both must grant. |

The `WorkflowUiAccessCheckerInterface` service alias is deprecated, and it is only registered when the configured
class implements that interface. Migrate:

```php
// Before (1.13)
final class WorkflowUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    public function isGranted(Request $request): bool { /* … */ }
}

// After (1.14)
final class WorkflowUiAccessChecker implements WorkflowUiAccessPolicyInterface
{
    public function allows(WorkflowUiAccessCheck $check): bool { /* $check->actor, $check->path(), … */ }
}
```

**Keep implementing both interfaces until no service of yours injects `WorkflowUiAccessCheckerInterface`.** The new
one is asked either way, but the deprecated alias is only registered for a class that implements that interface: with
a class that implements only `WorkflowUiAccessPolicyInterface`, any service (or decorator) that still injects or
decorates `WorkflowUiAccessCheckerInterface` fails the container build. A decorator of `WorkflowUiAccessPolicyInterface`
wraps the policy selected by the configuration (role-based, allow-all, deny-all or your service); it needs an
`access_checker` that implements the new interface (the build fails otherwise). Whenever both ports end up being asked
(both must grant), a deprecation names the deprecated-port service(s) to migrate.

The constructor of the internal
`WorkflowUiAccessSubscriber` gained two optional arguments (`$accessPolicy`, `$tokenStorage`) and its first argument
is now nullable.

### flush_after_apply

`runtime.flush_after_apply` keeps its 1.13 default (`true`) when you do not set it, so upgraded applications keep
flushing; a deprecation tells you to set it explicitly. The 1.14 Flex recipe writes `false` for new installs, and the
default becomes `false` in 2.0. To keep the current behaviour without the deprecation:

```yaml
nowo_workflow:
    runtime:
        flush_after_apply: true
```

To move to the 2.0 behaviour, set `false` and flush (or save through your repository) after `apply*()`.

### Private entity manager for the definitions

The admin UI, `nowo:workflow:seed-demo` and `WorkflowDefinitionRepository` now read and write the definitions
through a private entity manager of the bundle (same connection, mapping, listeners and SQL filters as your manager).
Visible differences:

- saving a definition no longer flushes changes your application left pending, and a failed save no longer resets
  your entity manager;
- entities returned by `WorkflowDefinitionRepository` are managed by the private manager: persist changes to them
  with `$repository->save($definition)` / `$repository->remove($definition)` (new in 1.14), not through your own
  manager (your `flush()` no longer writes them).
  Creating a `new WorkflowDefinition()` and persisting it with your own manager still works;
- in long-running workers the definitions are re-read at each request, Messenger message and console command.

Notes:

- `save($definition, false)` / `remove($definition, false)` only schedule the change in the private manager. Flushing
  it later is supported within the same request, message or command; keeping such unflushed changes across a rollback
  of a transaction your application opened is **not** supported (the private manager may be replaced after the
  rollback and the change dropped). Flush right away (the default) when you work inside your own transactions.
- `doctrine.private_entity_manager: false` restores the 1.13 behaviour for one minor (deprecated, removed in 2.0). In
  that mode `save()` / `remove()` flush the application's **default** entity manager, including every change your
  application left pending in it.
- `doctrine.entity_manager` names the source manager when the entities are mapped on a dedicated one. Without it, the
  source is the manager 1.13 used: the first manager of `doctrine.orm.entity_managers` that maps the bundle entities
  (resolved when the container is built, host mapping passes included). For example, when your default manager is
  named `main`, the bundle mapping lands on a manager named `default` and that one keeps being used. A deprecation asks
  you to set `doctrine.entity_manager` when it is not your default manager; the build fails only when no manager maps
  the entities.

### XML mapping

`src/Resources/config/doctrine/*.orm.xml` maps the four entities with the same tables, columns and constraints as the
attributes. Nothing changes unless you opt in with `doctrine.mapping_type: xml`, or with `doctrine.register_mapping:
false` and your own mapping of that directory (DDD hosts, see [USAGE.md](USAGE.md#ddd--hexagonal-host)).

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

**Workflow events now fire.** Up to 1.12.0 the database workflows were built without an event dispatcher, so the
standard Symfony workflow events were never dispatched. From 1.13.0 they are, on the application `event_dispatcher`,
with the definition **slug** as workflow name:

- Listeners on `workflow.guard`, `workflow.<slug>.guard[.<transition>]` are called by `can()`, `getEnabledTransitions*()`
  and `apply*()`; a listener that calls `$event->setBlocked(true)` now **blocks** the transition (`apply*()` throws
  `WorkflowTransitionBlockedException`, the transition disappears from `getEnabledTransitions*()`).
- Listeners on `workflow.leave`, `workflow.transition`, `workflow.enter`, `workflow.entered`, `workflow.completed`,
  `workflow.announce` (global ones included, e.g. a generic audit listener) start receiving the events of the
  bundle's workflows too. Check that global listeners cope with subjects / workflow names they did not see before
  (filter on `$event->getWorkflowName()` or the subject class). An exception thrown there aborts `apply*()`: the
  marking is already changed in memory when `completed` / `announce` listeners run, and the flush has not happened.
- `completed` / `announce` listeners run **before** the applicator's flush. Do not publish messages or call other
  systems from them; record the event and dispatch it after the commit. See
  [USAGE: Listeners run before the flush](USAGE.md#listeners-run-before-the-flush).
- Guards keyed on `workflow.<slug>.guard.<transition>` stop applying when the slug or the transition is renamed in the
  CRUD UI. Put invariants in a `workflow.guard` listener filtered on the subject class and the target place. See
  [USAGE: Slugs and transition names are configuration](USAGE.md#slugs-and-transition-names-are-configuration).
- **Slug collision check.** A database slug equal to the name of a workflow under `framework.workflows` would share
  its `workflow.<name>.*` events (framework listeners and guard expressions would receive your database subjects).
  The registry now throws `Nowo\WorkflowBundle\Exception\WorkflowNameCollisionException` when it builds such a
  workflow. Before upgrading, check that no database slug equals a framework workflow name, and rename it if one does
  (`php bin/console debug:container --tag=workflow` lists the framework names).

**Blocked transitions throw `WorkflowTransitionBlockedException`.** `apply*()` no longer pre-checks `can()`: it calls
Symfony's `apply()` directly, so each guard listener runs once per call (it used to run twice for the applied
transition). A transition blocked by the marking or by a guard throws
`Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException`, which **extends `InvalidArgumentException`**
(existing `catch (InvalidArgumentException)` blocks keep working). Its message now contains the blocker messages, e.g.
`Transition "approve" is not enabled on workflow "expense_approval": Budget exceeded.` (was `Transition "approve" is
not enabled for the current marking on workflow "expense_approval".`); `getBlockerMessages()`, `getBlockerCodes()` and
`getTransitionBlockerList()` expose them. An undefined transition name still throws a plain `InvalidArgumentException`,
now with `Transition "x" is not defined on workflow "y".`. Update code that matches on the old message text.

To keep the 1.12 behaviour (no events), override the service argument:

```yaml
services:
    Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry:
        arguments:
            $eventDispatcher: null
```

**New option `runtime.flush_after_apply`** (default `true`, unchanged behaviour): set it to `false` to persist the
subject yourself; `apply*()` then only changes the marking. See [CONFIGURATION.md](CONFIGURATION.md).

## To 1.12.0

From **1.11.2**:

```bash
composer update nowo-tech/workflow-bundle
```

**FormKit `^2.16`:** `nowo-tech/form-kit-bundle` stays a required dependency, now `^2.16`, and
`Nowo\FormKitBundle\NowoFormKitBundle` must be registered: every bundle form type, including the embedded
`PlaceMultiSelectType`, is a FormKit Type on the profile `nowo_form_kit.profiles.workflow`. Field classes and
conventions are customised through that profile (see [CONFIGURATION](CONFIGURATION.md)), not through `twig.form_themes`.
Field names did not change.

## To 1.11.2

From **1.11.1**:

```bash
composer update nowo-tech/workflow-bundle
```

The admin UI routes are no longer picked up by the Symfony >= 7.3 default `routing.controllers` import. If your
application never imported them and reached the UI anyway (on `/` instead of `web_ui.path_prefix`), import them
explicitly:

```yaml
# config/routes/nowo_workflow.yaml
nowo_workflow:
    resource: '@NowoWorkflowBundle/Resources/config/routes.yaml'
```

## To 1.11.1

From **1.11.0**:

```bash
composer update nowo-tech/workflow-bundle
```

- **Upgrade before running `nowo:workflow:sync-schema` again.** Earlier versions could emit `DROP TABLE` for every non-bundle table (no Doctrine `schema_filter`). From this release the command only creates or alters the bundle tables. If you ran it on a shared database, check your tables and restore from backup if needed.

## To 1.11.0

From **1.10.1**:

```bash
composer update nowo-tech/workflow-bundle
```

```bash
composer update nowo-tech/workflow-bundle
php bin/console assets:install
php bin/console cache:clear
```

- **CSRF fail-closed (REQ-SEC-005).** With `nowo_workflow.web_ui.enabled: true` (the default) the container no longer
  compiles without CSRF protection: enable `framework.csrf_protection` and `framework.form.csrf_protection`
  (`symfony/security-csrf`, already a dependency), or set `nowo_workflow.web_ui.enabled: false` when the application
  only uses the runtime registry.
- **Asset package `nowo_workflow` (REQ-ASSETS-004).** The bundle now registers `framework.assets.packages.nowo_workflow`
  (`base_path: /bundles/nowoworkflow`) and its templates load `asset('css/nowo-workflow.css', 'nowo_workflow')`,
  `asset('js/nowo-workflow.js', 'nowo_workflow')` and `asset('vendor/tom-select/…', 'nowo_workflow')`. Template
  overrides that hard-code `asset('bundles/nowoworkflow/…')` keep working but should switch to the package form; do
  not declare a `nowo_workflow` package in the application's `framework.yaml`.
- **Collection editor markup.** `_collection_section.html.twig` renders the entry prototype in
  `<template data-collection-prototype>` instead of a `data-prototype` attribute (the JS no longer uses `innerHTML`).
  Full copies of that partial under `templates/bundles/NowoWorkflowBundle/` must make the same change, or the
  "add" buttons stop working.
- **`metadata` column default dropped.** `WorkflowDefinition::$metadata` (JSON) has no database default any more (MySQL
  rejects it with error 1101); the PHP default `[]` still applies. `doctrine:schema:update` / a new migration shows
  `ALTER … metadata DROP DEFAULT` on PostgreSQL; apply it (no data change). `nowo:workflow:sync-schema` now also
  works on MySQL / MariaDB (existing foreign keys, indexes and columns are reported as already applied).
- **Logging (REQ-OBS-001).** `nowo:workflow:sync-schema`, `nowo:workflow:seed-demo` and the UI delete action log through
  the autowired `logger` service (PSR-3, `psr/log` is now an explicit dependency). See
  [SECURITY.md](SECURITY.md#logging).

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/workflow-bundle
```

## To 1.10.0

From **1.9.2**:

```bash
composer update nowo-tech/workflow-bundle
```

## To 1.9.2

From **1.9.1**:

```bash
composer update nowo-tech/workflow-bundle
```

Requires UiKit **^2.2** (`composer require nowo-tech/ui-kit-bundle:^2.2`). No configuration change. What hosts may
notice:

- Overrides of `workflow_definition/_flow_dialogs.html.twig` that kept `data-workflow-dialog-swap` on transition
  links: replace it by `data-nowo-ui-dialog-replace` (the bundle script no longer handles the old attribute; without
  either, the transition dialog opens on top of the place dialog).
- Host layouts with their own UiKit script list: include `{{ include(nowo_ui_partial('scripts')) }}` instead (the
  show / edit pages still load `nowo-ui-dialog.js` themselves for such layouts).
- A host theme that styles `btn_danger` for the collection remove confirmation sets `btn_danger_solid` too.
- UiKit 2.2 default theme: small uppercase table headers, striped rows, numeric columns end-aligned, Tom Select chips
  in the primary colour (UiKit UPGRADING "Template contract (2.2)").

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/workflow-bundle
```

No configuration change. Hosts that override `@NowoWorkflowBundle/workflow_definition/_flow_diagram.html.twig`:
the branching diagram is now an SVG built from `graph.layout.diagram` (`nodes` with `x`/`y`/`width`/`height`,
`edges` with `path`, `labelLeft`/`labelTop`/`labelWidth`/`labelHeight`); the old flex markup (`__grid`, `__column`,
`__bridge`, `__spacer` classes and their CSS) is gone. `graph.layout.columns`, `bridges` and `backEdges` are still
provided (now from the corrected ranking: `backEdges` holds only true loops).

## To 1.9.0

From **1.8.2**:

```bash
composer update nowo-tech/workflow-bundle
```

From **1.8.2** — admin pages on the UiKit page contract (REQ-UI-003); requires `nowo-tech/ui-kit-bundle` **^2.1**.
Minor release: the old configuration keeps working (deprecated), but the page markup changed.

```bash
composer update nowo-tech/workflow-bundle nowo-tech/ui-kit-bundle -W
php bin/console assets:install
php bin/console cache:clear
```

### Template contract

What a host may notice:

| Page | Before | Now |
| --- | --- | --- |
| Dashboard | `dashboard/index.html.twig` on `base.html.twig`, paginated table | `page/dashboard` (`DashboardPage`): KPI tiles + definitions card (`nowo_ui_dashboard_cards`) |
| Definitions | `workflow_definition/index.html.twig`, local `_pagination` | `page/list` (`ListPage`): `nowo_ui_list_row`; row actions view / edit / **delete** (UiKit confirm `<dialog>`) |
| Detail | `workflow_definition/show.html.twig`, inline `confirm()` | `page/show` (`ShowPage`): `nowo_ui_show_sections` (diagram + sections), `nowo_ui_modals` (diagram dialogs) |
| New | `workflow_definition/form.html.twig` (`title`, `form`, `section`) | same file on `page/form` (`FormPage`): `nowo_ui_form_body` |
| Edit sections | `workflow_definition/form.html.twig` + `_edit_nav` | `workflow_definition/edit.html.twig` on `page/settings` (`SettingsPage`, tabs): `nowo_ui_settings_section` |

- Templates receive `page` (UiKit view model) instead of `definitions` / `list_*` / `title` / `form`; the show page
  still gets `definition` and `graph`, the edit page `definition` and `section`, the dashboard `definitions` and
  `total`.
- Removed templates: `layout.html.twig`, `_framework_assets.css.twig`, `_framework_assets.js.twig`,
  `_pagination.html.twig`, `workflow_definition/_edit_nav.html.twig`, `_match_rules_section.html.twig`,
  `_places_section.html.twig`, `_transitions_section.html.twig`, `_collection_manager.js.twig`.
- Dialogs are native `<dialog>`s: the diagram and collection editor dialogs render UiKit's `dialog` partial (slots
  `dialog`, `dialog_header`, `dialog_title`, `dialog_body`, `dialog_footer`; sizes `md` / `lg`) and open through
  `data-nowo-ui-dialog-open` (`nowo-ui-dialog.js`, loaded by UiKit layouts and by the show / edit pages); the delete
  confirmation uses the UiKit `confirm` partial and needs `nowo-ui-confirm.js` in your layout (UiKit layouts load
  it). Bootstrap JS is no longer needed. CSS aimed at `.nowo-workflow-dialog` or `[data-workflow-dialog]` must
  target the UiKit dialog slots (`nowo_ui_kit.theme.bundles.NowoWorkflowBundle`) instead.
- Form controls carry `nowo-ui-input nowo-ui-input--full` (UiKit 2.1 `input_full` slot; plus `form-control` on
  Bootstrap stacks); the bundle CSS no longer overrides `.nowo-ui-input`.
- Assets load from `bundles/nowoworkflow/` (run `assets:install`); Tom Select is bundled, no CDN.
- Forms no longer apply `bootstrap_5_layout.html.twig` on their own unless the deprecated `ui.css_framework` is set
  (then through `FormPage::$formThemes` / `SettingsSection::$formThemes`, not `form_theme` in the templates):
  configure `twig.form_themes` (or the FormKit `workflow` profile) for your stack.

Migrating overrides: delete copies under `templates/bundles/NowoWorkflowBundle/` and theme the view types once in
UiKit (`nowo_ui_kit.theme`, `templates/bundles/NowoUiKitBundle/page/*.html.twig`). For a Workflow-only change, use
`nowo_workflow.web_ui.templates.<page>` with a template that extends `@!NowoWorkflowBundle/…` and overrides a data
block ([TEMPLATES.md](TEMPLATES.md#per-page-overrides)).

### Configuration

| Deprecated (1.9, removed in 2.0) | New |
| --- | --- |
| `enabled` | `web_ui.enabled` |
| `ui.path` | `web_ui.path_prefix` |
| `ui.layout_template` | `web_ui.layout_template` (`null` = `nowo_ui_kit.layouts.admin`) |
| `ui.list_page_size` / `ui.default_locale` / `ui.locales` | `web_ui.list_page_size` / `web_ui.default_locale` / `web_ui.locales` |
| `ui.required_roles` | `security.access_roles` |
| `ui.css_framework` / `ui.icon_set` | `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set` |

```yaml
# Before
nowo_workflow:
    ui:
        path: '/workflow'
        layout_template: 'base.html.twig'
        css_framework: bootstrap5

# After
nowo_ui_kit:
    css_framework: bootstrap5
    layouts:
        admin: 'layouts/admin.html.twig'   # renders the UiKit canonical blocks (CONFIGURATION.md)
nowo_workflow:
    web_ui:
        path_prefix: '/workflow'
```

While `ui.layout_template` is set, pages render through the deprecated `@NowoWorkflowBundle/base.html.twig` bridge
into that layout (its `title`, `stylesheets`, `body` and `javascripts` blocks), as in 1.x. The removed default
`@NowoWorkflowBundle/layout.html.twig` maps to the UiKit layout.

## From 1.8.1 to 1.8.2

From **1.8.1** — Twig coding-standard cleanup; twig-lint in CI

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.8.0 to 1.8.1

From **1.8.0** — default layout no longer renders page content twice (form pages 500)

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.7.0 to 1.8.0

From **1.7.0** — fixes - Doctrine proxies/subclasses resolved, normalized context, scoped form themes, configured locales, Symfony >=7.4.

```bash
composer update nowo-tech/workflow-bundle
```

- **Requirements:** Symfony `^7.4 || ^8.0` (7.0–7.3 are no longer allowed) and `symfony/yaml` is now required at runtime.
- **Form themes:** the bundle no longer adds `bootstrap_5_layout.html.twig` / `bootstrap_4_layout.html.twig` to the global `twig.form_themes`; they now apply only to the Workflow UI forms. If your own forms relied on that side effect, add the theme to `config/packages/twig.yaml` (`twig: { form_themes: ['bootstrap_5_layout.html.twig'] }`).
- **Resolution:** definitions now also match Doctrine proxies, subclasses and implemented interfaces of their `subject_class`; when several classes match, the closest one wins before specificity/priority. Check for new `WorkflowAmbiguousMatchException`s if you had parent/child definitions with equal scores.
- **WorkflowContext:** non-string parameter values are cast to strings (booleans → `"true"`/`"false"`, `null` dropped); arrays and non-`Stringable` objects now throw `InvalidArgumentException`.
- **Locale switch:** `/locale/{_locale}` accepts the locales configured in `ui.locales` and answers 404 for any other locale.
- **Deprecated:** remove `nowo_workflow.environments` from your configuration (it never had an effect); register the bundle per environment in `config/bundles.php` instead.

## From 1.6.12 to 1.7.0

From **1.6.12** — security: UI fails closed when disabled, locale subscriber scoped to bundle routes, no open redirect.

```bash
composer update nowo-tech/workflow-bundle
```

- **Behaviour change:** with `enabled: false` the workflow UI routes now return 404 instead of being served without access checks; the default UI access checker is deny-all when security is not wired.
- The host request locale is no longer overridden (and no session is opened) on non-bundle routes.
- A `Referer` pointing to another host now redirects to the workflow dashboard after a locale switch.

## From 1.6.11 to 1.6.12

From **1.6.11** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.6.10 to 1.6.11

From **1.6.10** — dependency refresh only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/workflow-bundle
```

## From 1.6.9 to 1.6.10

From **1.6.9** — UI `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## From 1.6.7 to 1.6.8

No breaking changes. **No application upgrade steps.** Behaviour change for long-running processes: `DatabaseWorkflowRegistry::get()` memoizes built workflows only for the current main request. Outside of an HTTP request (console commands, Messenger workers) or when the registry is instantiated manually without a `RequestStack`, each `get()` / `has()` call reloads the definition from the database. Calling `invalidate()` after editing definitions is still supported but no longer required for other workers to see the change.

```bash
composer update nowo-tech/workflow-bundle
```

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) for worker-mode guidance (scenario B: kernel not reset).

## From 1.6.6 to 1.6.7

No breaking changes. **No application upgrade steps.** Hosts using the default demo layout pick up extracted CDN partials automatically. Optional: `ui.layout_template: '@NowoWorkflowBundle/layout_integrate_base.html.twig'` when the project layout is `base.html.twig`.

```bash
composer update nowo-tech/workflow-bundle
```

## From 1.6.5 to 1.6.6

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/workflow-bundle
```

# Upgrade Guide

## General process

1. Back up `config/packages/nowo_workflow.yaml`
2. Read [CHANGELOG.md](CHANGELOG.md) for breaking changes
3. Run `composer update nowo-tech/workflow-bundle`
4. Run `php bin/console nowo:workflow:sync-schema`
5. Clear cache: `php bin/console cache:clear`

## Upgrading to 1.6.5

Review Flex recipe `security_nowo_workflow.yaml` after update.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.4

Review Flex recipe `security_nowo_workflow.yaml` access rules after install/update.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.3

No application upgrade steps.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.2

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Continue requiring `nowo-tech/workflow-bundle` as before.

## Upgrading to 1.6.1

From 1.6.0 — maintainer/CI fix only (`composer.lock` content-hash + prepend coverage). No host migration.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.0

From 1.5.2 — FormKit admin forms, UiKit macros, Twig Extra (REQ-TWIG-004), Twig-CS-Fixer.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
php bin/console assets:install --symlink --relative public
```

Requires `nowo-tech/form-kit-bundle` ^2.0 and `nowo-tech/ui-kit-bundle` ^1.4. Hosts that render Twig templates need `twig/extra-bundle` + `twig/string-extra` (usually via Flex).

## Upgrading to 1.5.2

From 1.5.1:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or consumer code update is required. This patch tightens maintainer QA (`coverage-check`), PHPStan (`ignoreErrors: []`), Packagist metadata, Docker IPAM, and DBAL 4 sequence listing in `SchemaSyncService`.

## Upgrading to 1.5.1

From 1.5.0:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or consumer code update is required. This patch completes maintainer/demo standards: demo Makefile aliases (`make -C demo up`), open-PR gate in `release-check`, Symfony DebugBundle in the FrankenPHP demo, and HTTP smoke in `demo release-verify`.

## Upgrading to 1.5.0

From 1.4.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

See [CHANGELOG.md](CHANGELOG.md) for the full list. Summary for integrators:

### Breaking — Web UI private by default (REQ-UI-002)

- The CRUD UI is **no longer open by default**. Without `symfony/security-bundle`, container compilation **fails** unless you set `nowo_workflow.security.allow_unauthenticated: true` (local demos/tests only) or provide `security.access_checker`.
- With SecurityBundle, `RoleBasedWorkflowUiAccessChecker` is wired automatically using `security.access_roles` (default `ROLE_ADMIN`).
- `ui.required_roles` remains as a **BC alias** of `security.access_roles`.
- Empty `access_roles` / `required_roles` now means **no** bundle-level role check (previously denied everyone).
- Protect `ui.path` (default `/workflow`) with Symfony `access_control` in the host app.

**Minimal production config:**

```yaml
nowo_workflow:
    ui:
        path: '/workflow'
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/workflow, roles: ROLE_ADMIN }
```

### Web UI look-and-feel (REQ-UI-001)

New keys under `ui`:

| Key | Default |
| --- | ------- |
| `layout_template` | `@NowoWorkflowBundle/layout.html.twig` |
| `css_framework` | `bootstrap5` |
| `icon_set` | `bootstrap-icons` |
| `list_page_size` | `20` |

Pages now extend `@NowoWorkflowBundle/base.html.twig` and stack assets with `{{ parent() }}`. Set `layout_template` to your project layout. Dashboard and definition index lists are paginated (`?page=`); set `list_page_size: 0` only for small demos. See [CONFIGURATION.md](CONFIGURATION.md).

### Twig blocks

Stable blocks: `nowo_ui_content`, `nowo_ui_styles`, `nowo_ui_scripts`, `nowo_ui_page_header`, `nowo_ui_modals`, `nowo_ui_flashes`. Renaming these later is a breaking change.

### Recipe / demo

- Flex recipe YAML documents the new defaults.
- The Symfony 8 demo intentionally sets `security.allow_unauthenticated: true` (no SecurityBundle). Do **not** copy that into production.

## Upgrading to 1.4.3

From 1.4.2:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required for Symfony integrators. This release improves maintainer CI/git hygiene, PHP-CS-Fixer import rules, and the FrankenPHP demo (`FRANKENPHP_MODE`). Bundle consumers who do not run `demo/` are unaffected.

If you maintain a local clone of the demo, set `FRANKENPHP_MODE=classic` (or `worker`) in `demo/symfony8/.env` and recreate containers after pulling.

## Upgrading to 1.4.2

From 1.4.1:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release only renames and refines maintainer CI documentation (`GITHUB_CI.md`); Symfony integrators are unaffected.

## Upgrading to 1.4.1

From 1.4.0:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release adds maintainer-only git hygiene tooling, Code of Conduct, and CI/documentation updates; Symfony integrators are unaffected.

## Upgrading to 1.4.0

From 1.3.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release adds maintainer-only Spec Kit scaffolding and baseline documentation; Symfony integrators are unaffected.

## Upgrading to 1.3.0

From 1.2.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration is required.

### Optional: role-based CRUD UI protection

If you use **Symfony Security**, you can alias the built-in checker instead of writing a custom class:

```yaml
# config/services/nowo_workflow_security.yaml
services:
    Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface:
        class: Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker
        arguments:
            $requiredRoles: ['ROLE_ADMIN']
            $authorizationChecker: '@security.authorization_checker'
```

New Flex installs receive this file from the recipe (commented until `symfony/security-bundle` is installed). The `ui.required_roles` config key documents the intended roles but does **not** auto-register the checker — you must alias the service (or use `access_control`).

### Optional: enable new UI locales

Catalogs for `de`, `nl`, and `pt` ship with the bundle. Add them to `ui.locales` to show them in the locale switcher:

```yaml
nowo_workflow:
    ui:
        locales: [en, es, fr, it, de, nl, pt]
```

## Upgrading to 1.2.0

From 1.1.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

### Breaking: PHP and Symfony

| Requirement | 1.1.x | 1.2.0 |
|-------------|-------|-------|
| PHP | 8.1+ | **8.2+** |
| Symfony | 6.3, 7.x, 8.x | **7.x, 8.x only** |
| Symfony 8 + Doctrine | — | PHP **8.4+** and `doctrine/doctrine-bundle` **^3.2.4** |

If you run **Symfony 6.4** or **PHP 8.1**, stay on `1.1.x` until you upgrade the host application.

For **Symfony 8**, ensure the app uses `doctrine/doctrine-bundle` ^3.2.4 (requires PHP 8.4+). Symfony 7 apps can keep `doctrine-bundle` ^2.13.

## Upgrading to 1.1.0

From 1.0.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console nowo:workflow:sync-schema
php bin/console cache:clear
```

No database migration is required when keeping the default `table_prefix: workflow_` (same physical table names as 1.0.0).

### Optional: protect the CRUD UI

Register a custom `WorkflowUiAccessCheckerInterface` implementation. Without it, the UI remains open (same as 1.0.0). See [SECURITY.md](SECURITY.md#protecting-the-crud-ui).

### Optional: custom table prefix

If you set `table_prefix` to a value other than `workflow_`, run `nowo:workflow:sync-schema` after changing config. Existing installations should keep the default unless you intentionally rename tables.

### Translation overrides

If you override bundle translations, ensure files are named `NowoWorkflowBundle.<locale>.yaml` (not `nowo_workflow.<locale>.yaml`). The translation domain is still **`NowoWorkflowBundle`**.

### Custom registry or tests

`WorkflowApplicator` now depends on `WorkflowRegistryInterface`. Custom implementations or test doubles should implement that interface; `DatabaseWorkflowRegistry` remains the default service.

## Upgrading to 1.0.0

Initial release. Install with:

```bash
composer require nowo-tech/workflow-bundle
```

Register routes and sync schema as described in [INSTALLATION.md](INSTALLATION.md).

### Translation domain

UI translations use domain **`NowoWorkflowBundle`**. Override in `translations/NowoWorkflowBundle.<locale>.yaml`.

### Twig templates

Override under `templates/bundles/NowoWorkflowBundle/` (see [USAGE.md](USAGE.md)).

## Compatibility

| Bundle | Symfony | PHP |
|--------|---------|-----|
| 1.x | 7.4+ (PHP 8.2+), 8.x (PHP 8.4+) | 8.2 – 8.5 |
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `workflow` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

## Unreleased

## To 1.6.10

From **1.6.9** — UI `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.6.9

From **1.6.8** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.6.0

From **1.5.2** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

