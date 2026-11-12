# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.15.1] - 2026-11-12](#1151---2026-11-12)
- [[1.15.0] - 2026-11-12](#1150---2026-11-12)
- [[1.14.1] - 2026-11-12](#1141---2026-11-12)
- [[1.14.0] - 2026-11-12](#1140---2026-11-12)
- [[1.13.0] - 2026-11-10](#1130---2026-11-10)
- [[1.12.0] - 2026-10-30](#1120---2026-10-30)
- [[1.11.2] - 2026-10-30](#1112---2026-10-30)
- [[1.11.1] - 2026-10-29](#1111---2026-10-29)
- [[1.11.0] - 2026-10-29](#1110---2026-10-29)
- [[1.10.1] - 2026-10-28](#1101---2026-10-28)
- [[1.10.0] - 2026-10-28](#1100---2026-10-28)
- [[1.9.2] - 2026-10-18](#192---2026-10-18)
- [[1.9.1] - 2026-10-18](#191---2026-10-18)
- [[1.9.0] - 2026-10-18](#190---2026-10-18)
- [[1.8.2] - 2026-10-13](#182---2026-10-13)
- [[1.8.1] - 2026-10-13](#181---2026-10-13)
- [[1.8.0] - 2026-10-13](#180---2026-10-13)
- [[1.7.0] - 2026-10-13](#170---2026-10-13)
- [[1.6.12] - 2026-10-13](#1612---2026-10-13)
- [[1.6.11] - 2026-10-09](#1611---2026-10-09)
- [[1.6.10] - 2026-09-28](#1610---2026-09-28)
- [[1.6.9] - 2026-09-27](#169---2026-09-27)
- [[1.6.8] - 2026-09-25](#168---2026-09-25)
- [[1.6.7] - 2026-08-26](#167---2026-08-26)
- [[1.6.6] - 2026-08-24](#166---2026-08-24)
- [[1.6.5] - 2026-08-19](#165---2026-08-19)
- [[1.6.4] - 2026-08-19](#164---2026-08-19)
- [[1.6.3] - 2026-08-19](#163---2026-08-19)
- [[1.6.2] - 2026-08-18](#162---2026-08-18)
- [[1.6.1] - 2026-08-07](#161---2026-08-07)
- [[1.6.0] - 2026-08-04](#160---2026-08-04)
- [[1.5.2] - 2026-07-29](#152---2026-07-29)
- [[1.5.1] - 2026-07-27](#151---2026-07-27)
- [[1.5.0] - 2026-07-27](#150---2026-07-27)
- [[1.4.3] - 2026-07-22](#143---2026-07-22)
- [[1.4.2] - 2026-07-16](#142---2026-07-16)
- [[1.4.1] - 2026-07-15](#141---2026-07-15)
- [[1.4.0] - 2026-07-08](#140---2026-07-08)
- [[1.3.1] - 2026-07-07](#131---2026-07-07)
- [[1.3.0] - 2026-07-07](#130---2026-07-07)
- [[1.2.0] - 2026-06-30](#120---2026-06-30)
- [[1.1.0] - 2026-06-30](#110---2026-06-30)
- [[1.0.0] - 2026-06-10](#100---2026-06-10)

## [Unreleased]

### Added

- **Retire a tenant clone (Beacon, 1.16.0):** new status `WorkflowDefinitionStatus::Retired`. `WorkflowVersioning::retire()`
  / `reinstate()` and the UI actions "Retire" / "Reinstate" (permission `publish`, CSRF `retire<id>` / `reinstate<id>`,
  routes `nowo_workflow_definition_retire` / `_reinstate`): new subjects of the tenant run the global template again,
  subjects pinned to the clone keep their version. Deleting stays blocked while pins exist (owner decision
  2026-11-12). Retiring is refused without an enabled global template to fall back to. A retired clone can be
  reinstated, get a new draft, or be customized again from the template (`cloneTemplate()` then creates its next
  version). Logged like publish and delete (`retire_definition`, `reinstate_definition`). List badge "Retired".
- **Locked clone structure (Beacon):** `nowo_workflow.tenancy.lock_clone_structure` (default `false`). Tenant clones keep
  the template's places, transitions and initial place; only guards and conditions are edited. Enforced by the editor
  (disabled fields, no add / remove, notice) and by `WorkflowVersioning::publish()` (`WorkflowStructureLockedException`
  when the structure differs from the template version it was cloned from). A tenant cannot shadow a template slug with
  its own definition. `WorkflowDefinition::structure()`.
- **Translatable labels (Beacon):** places and transitions get `label_key` / `label_domain` (nullable columns,
  `setLabelTranslation()`, editor fields). The flow diagram shows the translation with a fallback to the plain label;
  the workflow metadata adds `label_key` / `label_domain`. New `Service\WorkflowLabelTranslator`.
- **`WorkflowRuntimeInterface::pin()` (Beacon):** pins a subject to the version it would start with, without any
  transition, event, initial marking or flush. Idempotent (`WorkflowPinMode::KeepExisting`), explicit re-pin
  (`WorkflowPinMode::Repin`, refused when the subject's places do not exist in the new version), tenant-checked. Returns
  `Model\WorkflowPinResult`.

### Fixed

- **Doctrine listeners under DoctrineBundle 3.x (Beacon):** `TablePrefixSubscriber` and `BinaryKeyCollationSubscriber`
  were tagged `doctrine.event_subscriber`, which DoctrineBundle 3 no longer registers: on DoctrineBundle 3 hosts the
  key-column collation was never added to the generated schema (so `doctrine:migrations:diff` proposed dropping a
  collation a migration had set) and `table_prefix` was ignored. Both are now plain listeners tagged
  `doctrine.event_listener` with an explicit event (`loadClassMetadata`, `postGenerateSchemaTable`), registered by
  DoctrineBundle 2.x and 3.x alike; they no longer implement `Doctrine\Common\EventSubscriber`. Kernel test
  `DoctrineListenersKernelTest` (runs on DoctrineBundle 3 in the Symfony 8.1 CI cells).
- **NO PAD key collation (PAD SPACE lesson):** `utf8mb4_bin` on MySQL 8 and MariaDB's `_bin` collations treat `'abc '`
  as equal to `'abc'`. The key columns (`tenant_key`, `slug`, `workflow_slug`, `subject_ref`) now get
  `utf8mb4_nopad_bin` on MariaDB and `utf8mb4_0900_bin` on MySQL 8.0.17+; MySQL < 8.0.17 (and a `server_version`
  without a patch number such as `8.0`) keeps `utf8mb4_bin`. Defence in depth: keys with spaces were already refused
  and every key is re-checked with `===`. Same rule as AuthKitBundle 1.46.0. New CI job `mysql-schema` (MySQL 8.4,
  MariaDB 11.4): after the schema is created the migrations diff is empty, a 1.15.1 column is altered once, and
  `acme`, `acme ` and `Acme` are three keys.
- **State machine transitions with several `from` places (Beacon):** the definition builder now mirrors
  FrameworkBundle: a `state_machine` transition becomes one Symfony `Transition` per `from` place with the same name,
  so it fires from any of its `from` places (before, a single `Transition` needed all of them marked and never fired
  on a state machine). As with FrameworkBundle's `StateMachineValidator`, a state machine transition needs at least one
  `from` and exactly one `to` place: the builder refuses another shape with an explicit `InvalidArgumentException`
  (before, several `to` places silently moved the subject to the first one) and the transition editor refuses to save
  it. A `workflow` transition is unchanged (all `from` places must be marked, any number of `to` places).
- **DoctrineBundle 3 with a custom `table_prefix`:** see UPGRADING before generating the migration (rename the tables
  by hand; the generated diff drops and recreates them).
- **SQL filter mirroring (audit NZ-I6):** the private manager registers the class of each enabled source filter
  *instance* (not the class named in the source configuration), so the mirror is exact, also when a filter is
  registered again under the same name with another class.

## [1.15.1] - 2026-11-12

### Fixed

- **Private manager repository factory (audit NX, NZ-M1):** `WorkflowEntityManager` no longer builds its private entity manager on
  DoctrineBundle's shared `ContainerRepositoryFactory`, which keeps one repository per manager instance for the life
  of the process: every replaced private manager (closed, stale, on another connection, or dropped at a worker
  boundary) stayed in memory, about 58 KB each, in FrankenPHP worker mode (kernel reset false). It uses a
  bundle-owned `PrivateManagerRepositoryFactory` on a clone of the source configuration: service repositories
  (`ServiceEntityRepositoryInterface`, for example the ones a host listener on the shared event manager loads) still
  come from the source factory (the container service, not keyed by manager), every other repository is built and
  kept per manager. Regression test `PrivateManagerRepositoryFactoryTest`.
- **SQL filter mirroring (audit NZ-L1):** a filter registered on the source configuration after the private manager
  exists, or registered again under the same name with another class, is now registered on the private manager too.

[1.15.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.15.1

## [1.15.0] - 2026-11-12

Multitenant workflows (owner request D-20261112-10, Beacon): tenant-scoped definitions with global templates,
versions pinned per subject, an embeddable per-organization UI, per-transition guards configured in the editor, an
after-commit audit event, and a framework-free runtime port. Opt-in: without `tenancy.tenant_resolver` the runtime and
the UI behave as 1.14. **A schema update is required.** See [UPGRADING](UPGRADING.md#to-1150) and
[MULTITENANCY.md](MULTITENANCY.md).

### Added

- **Tenant-scoped definitions** (`tenancy.tenant_resolver`, port `Contract\WorkflowTenantResolverInterface`, VO
  `Model\WorkflowTenant`): a definition belongs to a tenant or is a global template (`tenant_key` column, `''` =
  global). Resolution for a subject: its pinned version, else its tenant's current published version, else the global
  template; never another tenant's (a tampered pin is refused). A tenant that disabled its version gets no fallback.
  `WorkflowResolver::resolveForTenant()`, `WorkflowDefinitionRepository::findCurrent()` / `findForTenant()` /
  `findEnabledCandidatesForTenant()`, `DatabaseWorkflowRegistry::forSubject()` / `forDefinition()`.
- **Tenant isolation guard** (`EventListener\TenantIsolationGuardSubscriber`, tenancy only): a workflow built from any
  other version than the one the subject runs blocks every transition (`nowo_workflow.tenant_mismatch`).
- **Versions and template clones** (`Service\WorkflowVersioning`, `Enum\WorkflowDefinitionStatus`): drafts (the next
  version number), publish, clone a published global template into a tenant (`template_version`), delete a draft or
  every version of a scope. In-flight subjects are **pinned** to the version they started with
  (`workflow_subject_pin`, `Entity\WorkflowSubjectPin`, written with plain DBAL; subject reference from
  `Contract\WorkflowSubjectReferenceResolverInterface`); `forgetSubject()` removes a subject's pins.
- **Embeddable UI per tenant**: `web_ui.path_prefix` may hold route variables (`/organizations/{uuid}/settings/workflows`;
  `EventListener\WorkflowUiRouteContextSubscriber` keeps them in every bundle link), ports
  `Contract\WorkflowUiTenantResolverInterface` (scope of a request) and `Contract\WorkflowUiActionCheckerInterface`
  (`Model\WorkflowUiAction` view / edit / publish, `Model\WorkflowUiActionCheck`), asked on top of the 1.14
  `WorkflowUiAccessPolicyInterface`. List, dashboard, show / diagram and editor are filtered by scope (another tenant's
  definition: 404; a template from a tenant: read-only, 403 on change). New routes `nowo_workflow_definition_draft`,
  `_publish`, `_clone` (POST, CSRF). Badges for template, draft and version.
- **Per-transition guards** configured in the editor (`workflow_transition.guard_permission`, `guard_expression`,
  `automatic`): permission in the subject's context (`guards.permission_checker`,
  `Contract\WorkflowTransitionPermissionCheckerInterface`, `Model\WorkflowTransitionPermissionCheck`, actor from
  `Contract\WorkflowActorProviderInterface`), conditions in a closed language over the host's whitelist of scalar
  variables (`guards.variables_provider`, `Contract\WorkflowSubjectVariablesProviderInterface`,
  `Model\WorkflowSubjectVariables`; no function, method, property, index or arithmetic; 500 bytes, 128 tokens,
  depth 16; strict types; errors fail closed), automatic transitions (idempotent, stop on a repeated marking or after
  `guards.max_automatic_transitions`; `WorkflowApplicator::applyAutomaticTransitions()`).
- **Audit event** `Event\WorkflowTransitionAppliedEvent` (ids only: slug, definition version and tenant, transition,
  from / to, subject class and reference, actor reference, tenant, automatic flag), dispatched after the commit of the
  transaction that persisted the subject (DBAL 4 middleware on the connections of the definitions' and the default
  manager, savepoint aware; dispatched after the applicator's flush or at the end of the request / command / message,
  never from `postFlush`; dropped on rollback, or when not committed by the end of the unit). `audit.enabled`
  (default: on with tenancy).
- **Runtime port** `Contract\WorkflowRuntimeInterface` (`can()`, `apply()`, `enabledTransitions()`, `marking()`) with
  the VOs `Model\WorkflowTransitionResult` and `Model\WorkflowMarkingView`; default `Service\WorkflowRuntime`.
  `WorkflowRuntimePass` wires it: the host's own runtime wins; a 1.14 host registry or registry decorator keeps running
  behind it (with a deprecation; refused with tenancy); a class implementing neither port fails the build.
- `WorkflowApplicator::can()`, `applyWithAutomatic()`, `markingView()`.
- Docs: [MULTITENANCY.md](MULTITENANCY.md) (standard and DDD hosts, Beacon leave-approval example).
- Tests: tenant isolation matrix (runtime, registry, guard, pins; UI list, show / diagram, editor, publish, delete,
  clone), condition sandbox escapes (method / property / function / index / arithmetic, huge, deep), version pinning,
  after-commit audit (commit, rollback, no flush), equivalence without the resolver (same workflow events in the same
  order as 1.14, no tenancy service), runtime port equivalence with the applicator, 1,000-round worker test alternating
  two organizations without the services resetter, `lint:container` for five container shapes, DDD host using only the
  runtime port (XML mapping, two connections).

### Changed

- Schema: `workflow_definition` gains `tenant_key` (default `''`), `version` (default 1), `status` (default
  `published`) and `template_version`; its unique constraint `uniq_workflow_definition_slug` becomes
  `uniq_workflow_definition_scope (tenant_key, slug, version)`. `workflow_transition` gains `guard_permission`,
  `guard_expression`, `automatic` (default false). New table `workflow_subject_pin`. XML mapping updated (parity
  tested).
- `WorkflowDefinitionRepository::findOneBySlug()`, `findEnabledCandidates()`, `findAllEnabled()`, `countEnabled()` and
  `paginateByName()` read the global, current published version of each slug (every 1.14 row: same results).
- Built workflows carry `nowo_definition_id`, `nowo_definition_version` and `nowo_definition_tenant` workflow
  metadata, and the guard settings as transition metadata (additive).
- The Workflow UI controller actions take the `Request` (internal); `WorkflowPageFactory` methods take an optional
  `View\WorkflowUiScope`.

### Security

Hardening from the immediate audit NO of this release (before it shipped):

- **Exact tenant keys (M1):** every repository and pin-store result is re-checked in PHP against the exact tenant key,
  slug and subject reference (a case- or accent-insensitive collation can no longer return or collide another
  tenant's rows); on MySQL / MariaDB `tenant_key` and `subject_ref` use the binary collation `utf8mb4_bin`
  (`Doctrine\BinaryKeyCollationSubscriber`); tenant keys and subject references are printable ASCII without spaces.
  Publish, draft and delete act on the already authorised entity.
- **Tenant editors cannot rebind a definition (M2):** in a tenant scope the type, subject class and marking property
  are locked (from the template, or from `tenancy.subject_classes` for a new definition); place and transition names
  must match `^[a-z0-9_]{1,64}$` with tenancy.
- **Pin before apply (M3, NO-v N2):** concurrent first applies agree on one version; the pin insert runs in a savepoint
  inside an open transaction (a duplicate never aborts it), the winning pin is re-read with a locking read
  (`LOCK IN SHARE MODE` / `FOR SHARE`, visible under REPEATABLE READ), and when it still cannot be read
  `Exception\WorkflowPinConflictException` asks to retry: the proposed version is never used instead.
- **No audit dispatch from `postFlush` (M4)**; the audit middleware observes only the definitions' and the default
  manager's connections, and anything uncommitted at the end of a unit is dropped (L1).
- Router context prefix variables are removed at the next main request even if the previous one never finished (L2);
  concurrent draft / clone / create end with the existing row or a form error (L3); a guard port that throws blocks the
  transition (fail closed).
- **A published definition with pinned subjects cannot be deleted** (owner decision 2026-11-12,
  `Exception\WorkflowDefinitionInUseException`). The pin foreign key is `ON DELETE RESTRICT`, so a pin committed between
  the count and the delete makes the database refuse it too (NO-v N1).
- **Verification audit NO-v:** list totals, enabled counts and "latest version" of the UI scope are computed from
  exactly re-checked rows (N3); the binary collation is applied when the schema is generated (migrations, schema tools,
  `sync-schema`), whatever the metadata cache holds, and also covers `slug` / `workflow_slug` (N3, N7); the audit
  boundaries follow the outermost unit (a nested command or request end keeps the outer transaction's events) and a
  main request drops leftovers of a unit that never ended (N4); main requests are counted at `finish_request` and
  healed at a top-level `kernel.terminate` (N5); a main request inside a command or message does not clear the
  private manager (N6); slugs follow the strict name pattern with tenancy (N7).

### Fixed

- **Console boundary tracking (audit NM, B6):** `WorkflowUnitOfWorkSubscriber` counted console depth with `++` / `--`
  at priorities ±4096, so a listener that threw or stopped the propagation could leave the counter behind in a
  long-running process, and the outermost command then no longer cleared the bundle's private entity manager. Each run
  is now a frame (command + input) pushed and popped at the extreme priorities, restoring the message / request state
  it saved; a frame whose `console.error` listener threw is dropped at the next unit boundary; main requests are
  counted; `WorkerMessageSkipEvent` (from `messenger:failed:retry`) ends the message unit. Precondition of the leak:
  a long-running process without the services resetter between units.
- `nowo:workflow:sync-schema` on SQLite dropped a table it had to rebuild to add columns (the rebuild's `CREATE TABLE`
  was filtered as "already exists"), and created the indexes of an existing table before the columns they need. Both
  are needed to upgrade a 1.14 schema; a test migrates a 1.14 `workflow_definition` table.

### Deprecated

- `Contract\WorkflowRegistryInterface` as a port for hosts (it returns a Symfony type, REQ-ARCH-001 rule 3): call
  `WorkflowRuntimeInterface`. It stays the SPI `WorkflowApplicator` asks until 2.0; a host implementation or decorator
  triggers a deprecation when the container is built.

[1.15.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.15.0

## [1.14.1] - 2026-11-12

Fixes for three Low findings of the 1.14.0 audit (NF3). Nothing to change in your application. See
[UPGRADING](UPGRADING.md#to-1141).

### Fixed

- **Rollback middleware on every candidate connection (audit NF3-1):** when a Doctrine mapping driver chain cannot be
  inspected at build time, the private entity manager keeps the 1.13 runtime rule (`getManagerForClass()`), and the
  rollback middleware was attached to the default manager's connection, which may not be the one that rule picks. It
  is now attached, in order, to the connection of every manager that rule may pick: each manager not proven to not map
  the bundle entities, up to and including the first one proven to map them (once per connection).
- **A throwing `onClear` listener no longer aborts the boundary (audit NF3-2):** `WorkflowEntityManager::reset()`
  wrapped the pending-changes inspection but not the final `clear()` of the dropped private manager. A host `onClear`
  listener that throws is now caught and logged as a warning (exception class only), like the inspection failure; the
  manager was already dropped before.
- **Optional deprecated-port decorators no longer fail the build (audit NF3-3):** with
  `security.access_checker` implementing only `WorkflowUiAccessPolicyInterface`, a decorator of the deprecated
  `WorkflowUiAccessCheckerInterface` registered with `on_invalid: ignore` (`IGNORE_ON_INVALID_REFERENCE`) made
  `WorkflowUiSecurityPass` fail the build. Such a decorator asked to be dropped when there is nothing to decorate, so it
  is now skipped and Symfony removes it; a decorator without that flag still fails the build (audit NF2-4b).

[1.14.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-11-12

Framework-free Workflow UI access port, a private entity manager for the definitions, an explicit `flush_after_apply`
for new installs and an XML mapping (REQ-ARCH-001 rules 2, 3 and 6). See [UPGRADING](UPGRADING.md#to-1140).

### Added

- `Contract\WorkflowUiAccessPolicyInterface::allows(Model\WorkflowUiAccessCheck $check)`: framework-free twin of the
  Workflow UI access checker (no `Request`, `TokenInterface` or `UserInterface`). The VOs `Model\WorkflowUiAccessCheck`
  and `Model\WorkflowActor` keep the path, the route parameters and the user object in an internal `WeakMap`, so no
  dump or serialization shows them. The built-in role-based, allow-all and deny-all checkers implement it.
- Private entity manager for the workflow definitions (`Doctrine\WorkflowEntityManager`, internal): created on the
  source manager's connection, configuration and event manager, SQL filters mirrored, recreated when closed, kept while
  it has pending work after a rollback, dropped at every outermost unit-of-work boundary
  (`EventListener\WorkflowUnitOfWorkSubscriber`: main request, Messenger message, outermost console command, with a
  nesting depth and an in-flight message flag) and at `kernel.reset`. Options `doctrine.entity_manager` and
  `doctrine.private_entity_manager` (deprecated opt-out).
- XML mapping of the four entities (`src/Resources/config/doctrine/*.orm.xml`, parity with the attributes tested);
  options `doctrine.mapping_type` (`attribute` / `xml`) and `doctrine.register_mapping`.
- `WorkflowDefinitionRepository::save()` / `remove()` (persist / remove, flush unless `$flush` is false) to write
  definitions through the repository's manager.
- Flex recipe `1.14`: writes `runtime.flush_after_apply: false` for new installs.
- Tests: access-decision equivalence matrix against a verbatim copy of the 1.13.0 subscriber and checker (new port,
  deprecated port, dual service, decorator; mutation-checked), `lint:container` for every port shape, a DDD host
  kernel (XML mapping, dedicated manager, host policy), a flush / no-flush database-state equivalence test and a
  1,000-request worker test without the services resetter.

### Changed

- The admin UI, `nowo:workflow:seed-demo` and `WorkflowDefinitionRepository` use the private entity manager: a
  definition save never flushes the application's pending changes, and a failed one never resets the application's
  entity manager (REQ-ARCH-001 rule 6).
- `nowo_workflow.security.access_checker` naming a service that implements neither access port now fails the
  container build (it failed with a `TypeError` on the first request before).
- `WorkflowUiAccessSubscriber` (internal): the first constructor argument is nullable; `$accessPolicy` and
  `$tokenStorage` were added. With no port wired it denies.
- Decorators of the 1.14 port wrap the policy selected by the configuration; with a decorator of the deprecated port
  as well, both ports are asked (both must grant) and a deprecation names the deprecated-port service to migrate. A
  1.14-port decorator over an `access_checker` that implements only the deprecated port fails the build (audit NF-1).
- Without `doctrine.entity_manager` the private manager's source is the manager that maps the bundle entities first
  (the 1.13 `getManagerForClass()` rule), resolved at build time and shared with the rollback middleware; a
  deprecation asks for the explicit option when it is not the default manager, and the build fails only when no
  manager maps the entities (audit NF-3, NF2-1, NF2-3). SQL filter parameters are copied as stored, list types included (NF-7).
- A boundary that drops pending private-manager changes logs a warning with the entity classes and count (NF-2); the
  manager is dropped even when those changes cannot be inspected (NF2-2).
- `security.access_checker` on the 1.14 port next to a different host alias of that port, or a decorator of the
  deprecated port over an `access_checker` that implements only the 1.14 port, fails the build (NF2-4).

### Deprecated

- `Contract\WorkflowUiAccessCheckerInterface` and its service alias (removed in 2.0): implement
  `WorkflowUiAccessPolicyInterface`. A service implementing only the deprecated interface triggers a deprecation when the
  container is built and keeps working unchanged.
- Not setting `runtime.flush_after_apply`: the 1.13 default (`true`) stays for upgraded applications, with a
  deprecation; it becomes `false` in 2.0.
- `doctrine.private_entity_manager` (any value): the private entity manager becomes the only mode in 2.0.

[1.14.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.14.0

## [1.13.0] - 2026-11-10

Database workflows now dispatch the standard Symfony workflow events, so guard listeners run (they never did before 1.13.0). See [UPGRADING](https://github.com/nowo-tech/WorkflowBundle/blob/v1.13.0/docs/UPGRADING.md#to-1130).

### Security

- **Guard listeners were never called before 1.13.0** (affected `>= 1.0.0, < 1.13.0`; independent audit KR, finding G1):
  the database workflows were built without an event dispatcher, so a host listener on `workflow.guard` or
  `workflow.<slug>.guard[.<transition>]` (for example an authorization or business-rule check) never ran and every
  transition the marking allowed was applied (fail-open). If you relied on such guards, review the transitions applied
  while running an affected version. Fixed by the event dispatch below.

### Fixed

- **Workflow events are dispatched (DDD audit 2026-11-10, G1):** `DatabaseWorkflowRegistry` built every `StateMachine` /
  `Workflow` with a `null` event dispatcher, so no `workflow.*` event (`guard`, `leave`, `transition`, `enter`,
  `entered`, `completed`, `announce`) ever fired: host guard listeners could not block a transition, `can()` /
  `getEnabledTransitions*()` offered transitions the domain refuses, and nothing could react to an applied transition.
  The registry now receives the application `event_dispatcher` (optional `$eventDispatcher` constructor argument) and
  the workflows dispatch the events with the definition **slug** as workflow name (`workflow.<slug>.guard.<transition>`, …).
  Marking store unchanged (`MethodMarkingStore` on `marking_property`).

- **Each guard runs once per `apply*()` and the blocker reason is kept (audit KR-W-L1):** `WorkflowApplicator` pre-checked
  `can()` before `apply()`, so the guard of the applied transition ran twice, and a blocked transition threw a generic
  "not enabled for the current marking" message without the guard's reason. `apply*()` now calls `apply()` directly
  and turns Symfony's `NotEnabledTransitionException` into `WorkflowTransitionBlockedException` (extends
  `InvalidArgumentException`) with the blocker messages in its message and `getBlockerMessages()` /
  `getBlockerCodes()` / `getTransitionBlockerList()`. An undefined transition throws `InvalidArgumentException`
  ("is not defined on workflow").

### Added

- **Slug collision check (audit KR-W-L3):** with events dispatched, `DatabaseWorkflowRegistry` refuses a database slug
  equal to a `framework.workflows` name (`WorkflowNameCollisionException`): both would dispatch the same
  `workflow.<name>.*` events. The names are collected by `FrameworkWorkflowNamesPass` into
  `nowo_workflow.framework_workflow_names` (new optional `$frameworkWorkflowNames` registry argument).
- **`runtime.flush_after_apply` (default `true`, DDD audit G2):** `false` makes `WorkflowApplicator::apply*()` only set
  the marking and leave persisting to the host (its repository / transaction boundary) instead of flushing every
  pending change of the default entity manager after each transition.

### Documentation

- USAGE: `completed` / `announce` listeners run before the applicator's flush, so domain events are recorded there and
  published after the commit (KR-W-L2); guards keyed on editable slug / transition names stop applying when renamed,
  use a `workflow.guard` listener filtered on the subject and the target place (KR-W-L3); the CRUD UI is deny-by-default
  (`ROLE_ADMIN`), not open (KR-W-I4). FRANKENPHP-WORKER-AUDIT re-audited for the event dispatcher (KR-W-I2); PSR.md
  notes that workflow events are only dispatched since 1.13.0.

[1.13.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.13.0

## [1.12.0] - 2026-10-30

Page forms are FormKit types with a bundle FormKit profile; requires nowo-tech/form-kit-bundle (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-1120).

### Changed

- **Forms are FormKit Types (REQ-TWIG-005 rule 11):** `PlaceMultiSelectType` (the transition source / target place
  multiselect) now carries `#[FormKitConfig('workflow')]` + `FormOptionsTrait` like the other form types, and
  `WorkflowTransitionType` resolves the `fromPlaces` / `toPlaces` options through the FormKit `workflow` profile (same
  labels, classes and field names; no convention help / placeholder). `PlaceMultiSelectType::widgetClass()` /
  `CSS_CLASS` expose the widget classes.
- `nowo-tech/form-kit-bundle` constraint raised to `^2.16` (bundle and demo).

[1.12.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.12.0

## [1.11.2] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1112).

### Fixed

- **Routes are opt-in (REQ-SF-004 rule 7):** the admin controllers (`DashboardController`, `WorkflowDefinitionController`, `LocaleController`) are excluded from the autoconfigured `Nowo\WorkflowBundle\` glob and registered with `autoconfigure: false` plus explicit `controller.service_arguments` / `container.service_subscriber` tags. On Symfony >= 7.3 the host's default `config/routes.yaml` (`routing.controllers`) no longer mounts the `nowo_workflow_*` routes on `/`, `/definitions`, … without `web_ui.path_prefix`; they only exist when the host imports `@NowoWorkflowBundle/Resources/config/routes.yaml`.

[1.11.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.11.2

## [1.11.1] - 2026-10-29

Workflow: sync-schema no longer drops host tables (all versions since 1.0.0) See [UPGRADING](UPGRADING.md#to-1111).

### Security

- **`nowo:workflow:sync-schema` could drop host tables.** With Doctrine ORM 3, `SchemaTool::getUpdateSchemaSql()` compares the whole database with the bundle entities, so the update SQL contained `DROP TABLE` (and on PostgreSQL `DROP SEQUENCE`) for every table and sequence that is not a bundle one, unless the host had a Doctrine `schema_filter`. The command now compares only the bundle tables. All versions since 1.0.0 are affected; do not run `sync-schema` with an older version on a database that holds other tables (REQ-SEC-004 re-audit H1, CWE-1188).

[1.11.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.11.1

## [1.11.0] - 2026-10-29

Workflow: MySQL demo + schema-sync fixes, scoped asset package, CSRF fail-closed, PSR-3 logging, no innerHTML See [UPGRADING](UPGRADING.md#to-1110).

### Added

- `framework.assets.packages.nowo_workflow` (`base_path: /bundles/nowoworkflow`) prepended by the extension; the
  templates load their CSS / JS / Tom Select files with `asset(…, 'nowo_workflow')` (REQ-ASSETS-004).
- `CsrfProtectionPass`: with `web_ui.enabled: true` the container refuses to compile without the CSRF token manager
  and the form CSRF extension, or with form CSRF disabled (REQ-SEC-005, fail closed).
- PSR-3 logging (`psr/log` required): schema sync and demo seeding log start / finish / failure, the UI logs
  definition deletions and rejected delete tokens; never tokens or personal data (REQ-OBS-001, REQ-CS-007).
- `scripts/validate-translations.php`: `make validate-translations` (and CI) check YAML syntax, the seven required
  locales and key parity with `en` (REQ-MAKE-004, REQ-I18N-002).

### Changed

- `nowo-workflow.js` no longer uses `innerHTML`: table cells are built with text nodes and new collection entries are
  cloned from `<template data-collection-prototype>` (was a `data-prototype` attribute); initialisation is idempotent
  (REQ-UX-001).
- Demo: MySQL 8.4 replaces PostgreSQL (REQ-DEMO-011); the Flex `compose.override.yaml` that published the database
  port is removed (REQ-DEMO-006); the `dns:` entries are commented (REQ-DEMO-009); demo `release-check` /
  `release-verify` run `update-bundle` (REQ-DEMO-007).
- PHPDoc descriptions on every public class and method of `src/` (REQ-CS-001).
- Docs: translation override procedure (REQ-I18N-001), browser JS / CSP notes, SECURITY.md logging, cryptography,
  dependencies and limits sections plus the full 12.4.1 checklist (REQ-SEC-001, REQ-SEC-002), PSR-3 adopted in
  `docs/PSR.md` (REQ-CS-007).
- Developer tooling: Rector targets PHP 8.2, the declared `php` floor (REQ-CS-003, REQ-SF-001).

### Fixed

- The schema could not be created on MySQL: the JSON `metadata` column of `workflow_definition` declared a literal
  default (MySQL error 1101). The default is dropped (the PHP default `[]` applies).
- `nowo:workflow:sync-schema` failed on MySQL when foreign keys / indexes / columns already existed (errors 1826,
  1061, 1060) — they are now counted as already applied, like PostgreSQL duplicates (incl. SQLSTATE 42710).
[1.11.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-10-28

Security: the UI access check also applies when the routes are imported with a name_prefix. See [UPGRADING](UPGRADING.md#to-1101).

### Security

- **UI guard no longer depends on route names.** `WorkflowUiAccessSubscriber` recognised Workflow UI requests only by
  the `nowo_workflow_` route-name prefix; importing the routes with a `name_prefix:` (or renaming them) skipped the
  access check. Requests handled by the bundle's controllers are now always checked.

[1.10.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-10-28

Performance: the definition list loads its collections with one query each instead of a cartesian fetch-join. See [UPGRADING](UPGRADING.md#to-1100).

### Performance

- **Definition list without a cartesian product.** `paginateByName()` fetch-joined places, transitions and match rules
  in one query (places × transitions × rules rows per definition, through a `Paginator`) only to print their counts.
  The page query now has no collection join (plain `LIMIT`) and the three collections are loaded with one `IN` query
  each (1 + 3 queries per page, no lazy load per row); an integration test pins the query count.

### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the playground create forms (`App\Form\PlaygroundSubjectType`), the transition buttons (`App\Form\TransitionType`, one CSRF-only form per transition) and the resolver GET filter (`App\Form\ResolverFilterType`, query names unchanged) render through Symfony Form Types with `form_start` / child loop / `form_end`; create and transition handlers validate with `handleRequest` + `isValid()` (stateless `submit` CSRF token, as configured in the demo) (REQ-TWIG-005).
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `coverage-check`, `validate-translations`, demo release-check); `check-open-prs` also fails on conflicted pull requests (REQ-REL-003).
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- Documentation: wave-1 REQ compliance — README GitHub stars badge uses the package name, documentation list order (Templates/PSR under Additional documentation), canonical issue templates, CONTRIBUTING Code of Conduct link and Cursor Agent queue settings (REQ-IDE-006), tables of contents on long docs, Spec Kit inventory refresh after the UiKit page migration (79/79).

[1.10.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.10.0

## [1.9.2] - 2026-10-18

Requires UiKit ^2.2; workarounds made unnecessary by 2.2 removed. See [UPGRADING](UPGRADING.md#to-192).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, numeric
  headers aligned with their cells, subtle badges, help text spacing). See [UPGRADING](UPGRADING.md#unreleased).
- Flow diagram: the transition links of a place dialog swap dialogs through the UiKit 2.2 opener
  (`data-nowo-ui-dialog-replace`, focus handed back to the diagram) instead of the bundle's own capture-phase
  handler (`data-workflow-dialog-swap` and its script removed).
- Transitions editor: Tom Select chips and focus ring painted from the UiKit primary token (UiKit
  `css/nowo-ui-tom-select.css`); the collection remove confirmation uses the solid destructive button
  (`btn_danger_solid`).
- `layout_integrate_base.html.twig` and the deprecated 1.x bridge `base.html.twig` print the UiKit runtimes through
  the UiKit `scripts` partial (the 1.x bridge loaded only the confirm runtime). Demo host layout likewise.

[1.9.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.2

## [1.9.1] - 2026-10-18

Flow diagram arrows reach their real targets (ranked layout, SVG connectors, only true back-edges listed as loops); end-aligned numeric columns, neutral Default badge, code slot for identifiers. See [UPGRADING](UPGRADING.md#to-191).

### Fixed

- **Flow diagram of branching definitions.** Transition labels and arrows pointed at the wrong places (e.g. "Submit
  for review" landed on Cancelled), Cancelled was ranked next to Review, and forward edges (review → cancel →
  cancelled) were listed under "Return / loop transitions". The new `WorkflowGraphLayout` ranks places by the longest
  path from the initial place, classifies only true back-edges (target ranked at or before the source, found by a
  depth-first search) as loops, orders each rank by barycentre sweeps, and draws the diagram as an SVG: one curved
  connector per edge from its source to the middle of its own target place (long edges pass through waypoints), each
  label on its own connector (moved along it when two labels would collide). Places and labels stay focusable
  buttons that open the place / transition dialogs; hovering or focusing a label highlights its connector.
- Demo: e2e covers the diagram geometry (every connector ends at the middle of its own target, only Resubmit and
  Reopen listed as loops); README screenshots re-captured (REQ-DEMO-013).

### Changed

- **Definitions list.** The match rules column is end-aligned like priority, places and transitions (`Column`
  `align: 'end'`; the cells keep `nowo-ui-cell--end`); the "Default" badge uses the neutral badge slot
  (`badge_neutral`) instead of the solid info badge, and its label is capitalised in every locale.
- Inline identifiers (slug, subject class, place names in the diagram, dialogs and loop list, and the code cells the
  collection editors build in JS — `data-code-class` on the collection root) use the UiKit `code` slot.
- `WorkflowGraphPresenter::present()` layout: `columns` are the ranks of the new layout, `bridges[<rank>]` lists the
  forward edges leaving that rank (also edges spanning several ranks), `backEdges` only true loops; new keys `ranks`
  (place → rank) and `diagram` (SVG geometry: `width`, `height`, `nodes`, `edges` with `path` and label box; `null`
  for linear definitions, which keep `sequence`).

[1.9.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-10-18

Workflow UI on the UiKit 2.1 standard page contract (REQ-UI-003): dashboard, list, show, form and settings scaffolds, UiKit native dialogs, no CDN assets (Tom Select vendored); deprecated `ui.*` keys mapped to `web_ui.*` / `security.*`. See [UPGRADING](UPGRADING.md#to-190).

### Added

- **UiKit 2.0 page contract (REQ-UI-003).** The admin pages extend UiKit scaffolds and receive view models from the
  new `Nowo\WorkflowBundle\View\WorkflowPageFactory`: dashboard → `page/dashboard` (KPI tiles + definitions card),
  definitions → `page/list` (view / edit / delete row actions, delete behind the UiKit confirm dialog, `Pagination`,
  empty state, breadcrumbs), detail → `page/show` (detail sections, created / updated meta, flow diagram with
  place / transition dialogs), new → `page/form`, edit → `page/settings` (one tab per section). See
  [TEMPLATES.md](TEMPLATES.md).
- Canonical configuration keys: `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.layout_template` (default `null` →
  `nowo_ui_kit.layouts.admin`), `web_ui.templates.<page>` (`dashboard`, `index`, `show`, `new`, `edit`),
  `web_ui.list_page_size`, `web_ui.default_locale`, `web_ui.locales`.
- Delete action on the definitions list rows and on the edit pages (same CSRF-protected `POST` route).
- Bundle assets in `src/Resources/public/` (`assets:install` → `bundles/nowoworkflow/`): `css/nowo-workflow.css`
  (diagram, dialog content and editors painted from `--nowo-ui-*` tokens), `js/nowo-workflow.js` (collection
  editors; the dialogs are UiKit's) and Tom Select 2.4.3 (`vendor/tom-select/`, Apache-2.0) for the transitions editor.
- Collection editors validate the entry inside the dialog (required fields) before applying it.
- Real translations (7 locales) for the new labels and for the FormKit placeholder / help convention keys of every
  form field.
- `PageContractTest` (UiKit `PageContractTestCase`) over every page; demo Playwright e2e (`make -C demo/symfony8
  test-e2e`) and WebP screenshots in `docs/images/demo/` (REQ-DEMO-013).

### Changed

- **Template contract:** every page template was rewritten on a UiKit scaffold (view model `page`, data blocks
  only); `workflow_definition/form.html.twig` is now the *new* page only and the edit sections render
  `workflow_definition/edit.html.twig`. Markup uses `nowo-ui-*` classes and UiKit theme slots, no framework
  utility classes, no inline `<script>` / `<style>` / `onsubmit` / `confirm()`.
- The dashboard shows KPI tiles and the first `web_ui.list_page_size` definitions (link to the full list) instead of a
  paginated table.
- Forms use the host form theme and the FormKit `workflow` profile; the profile is seeded with Bootstrap control
  classes only on Bootstrap stacks (`nowo_ui_kit.css_framework`), semantic classes otherwise. Text controls use the
  UiKit 2.1 `input_full` slot classes (`nowo-ui-input nowo-ui-input--full`), so the bundle CSS no longer overrides
  `.nowo-ui-input`.
- The deprecated `ui.css_framework` form themes are passed as `FormPage::$formThemes` /
  `SettingsSection::$formThemes` (UiKit 2.1) instead of `form_theme` tags in the page templates.
- Place / transition dialogs and the collection editor dialog render UiKit's `dialog` partial and open through
  `data-nowo-ui-dialog-open` (`js/nowo-ui-dialog.js`, which the show / edit pages also load for host layouts with
  their own script list); the editor dialog gains the UiKit header close button (discards the entry like Cancel).
- `PlaceMultiSelectType` no longer adds the Bootstrap `form-select` class.
- Flash messages are translated by the controller (UiKit's flashes partial prints them as given).
- Requires `nowo-tech/ui-kit-bundle` `^2.1` (collection prototypes in the contract test, `dialog` partial,
  `input_full` slot, page form themes).

### Deprecated

- `enabled` → `web_ui.enabled`; `ui.path` → `web_ui.path_prefix`; `ui.layout_template` → `web_ui.layout_template`
  / `nowo_ui_kit.layouts.admin` (still honoured through the deprecated `@NowoWorkflowBundle/base.html.twig` bridge);
  `ui.list_page_size`, `ui.default_locale`, `ui.locales` → `web_ui.*`; `ui.required_roles` →
  `security.access_roles`; `ui.css_framework` / `ui.icon_set` → `nowo_ui_kit.css_framework` / `icon_set`.
- Twig globals `nowo_workflow_layout_template`, `nowo_workflow_css_framework`, `nowo_workflow_icon_set`,
  `nowo_workflow_form_themes`; templates `base.html.twig`, `layout_integrate_base.html.twig`,
  `_locale_switcher.html.twig`. All removed in 2.0.

### Removed

- Default CDN layout `layout.html.twig` and the 4-framework CDN switch (`_framework_assets.css.twig` /
  `.js.twig`), local `_pagination.html.twig`, `workflow_definition/_edit_nav.html.twig`, the Bootstrap-modal
  collection partials (`_match_rules_section`, `_places_section`, `_transitions_section`,
  `_collection_manager.js.twig`) and the Tom Select CDN.
- `workflow_definition/_dialog.html.twig` (the bundle `<dialog>` shell on the `confirm_*` slots) and the
  `data-workflow-dialog-open` / `data-workflow-dialog-close` attributes: use UiKit's `dialog` partial and
  `data-nowo-ui-dialog-open` / `data-nowo-ui-dialog-close`.

### Fixed

- The definitions list showed fewer rows than `list_page_size` (the page limit applied to fetch-joined rows); it now
  paginates definitions (Doctrine `Paginator`).

[1.9.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.0

## [1.8.2] - 2026-10-13

### Changed

- **Twig coding standard**: `make twig-lint` (twig-cs-fixer) now passes with 0 errors (19 coding-standard findings, no template bugs). Admin templates use the `include()` function instead of the `{% include %}` tag, multi-line hashes/arrays get trailing commas and the form `id` attribute key is unquoted. Rendered markup is unchanged apart from insignificant whitespace (one newline after each include). CI runs `composer twig:lint` in a new **Twig lint** job.

[1.8.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.2

## [1.8.1] - 2026-10-13

### Fixed

- Default UI layout (`@NowoWorkflowBundle/layout.html.twig`) no longer renders `nowo_ui_content` / `nowo_ui_modals` a second time after the `body` block. Every Workflow UI page was output twice, and the definition form pages (`/workflow/definitions/new`, `/workflow/definitions/{id}/edit/*`) failed with HTTP 500 `Field "name" has already been rendered`. Hosts with their own `ui.layout_template` were not affected.

[1.8.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-10-13

### Fixed

- Workflow resolution now matches Doctrine proxies and subclasses of a definition's `subject_class` (real class + parents + interfaces, closest class first), consistent with `WorkflowApplicator`'s `is_a()` check; previously only the exact `::class` matched.
- `WorkflowContext` normalizes parameter values to strings (ints/floats cast, booleans `"true"`/`"false"`, `Stringable` cast, `null` dropped) so `get()` no longer throws `TypeError` for non-string values; arrays/objects are rejected with `InvalidArgumentException`.
- Locale switch route accepts every locale configured in `ui.locales` (e.g. `de`, `nl`, `pt`) instead of a hard-coded `en|es|fr|it`; locales outside `ui.locales` answer 404.
- Docs: `security.access_roles` cannot be empty unless `access_checker` or `allow_unauthenticated` is set; `connection` is the entity manager used by `nowo:workflow:sync-schema` (the mapping is always registered on the default entity manager).
- CI/tests: `LocaleControllerTest` reads the `#[Route]` attribute's public `requirements` property (available since Symfony 7.4) instead of `getRequirements()`, which no longer exists in Symfony 8.x.

### Changed

- Bootstrap form themes are applied only to the bundle's own forms (`{% form_theme %}` + Twig global `nowo_workflow_form_themes`) instead of being prepended to the global `twig.form_themes`, which restyled every host form.
- Symfony floor raised to `^7.4 || ^8.0` (the CI `7.0` cell actually resolved 7.4); `symfony/yaml` is now a runtime requirement (the extension uses `YamlFileLoader`).

### Deprecated

- `nowo_workflow.environments` has no effect and triggers a deprecation when set; it will be removed in 2.0.

[1.8.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-13

### Security

- `enabled: false` no longer disables UI access control (fail-open): Workflow UI routes now answer 404 and the access checker is always wired (fail-closed `DenyAllWorkflowUiAccessChecker` fallback; also the default alias).
- Locale switch (`/locale/{_locale}`) no longer redirects to arbitrary `Referer` URLs (open redirect); only same-host or local paths are followed, otherwise it redirects to the dashboard.

### Fixed

- `LocaleSubscriber` only applies `ui.default_locale` / the stored UI locale to `nowo_workflow_*` routes; host application requests keep their locale and no longer open the session.

[1.7.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.7.0

## [1.6.12] - 2026-10-13

### Dependencies

- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #49).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #50).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #51).

[1.6.12]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.12

## [1.6.11] - 2026-10-09

### Dependencies

- Dependabot: `doctrine/orm` 3.7.3, `nowo-tech/form-kit-bundle` 2.5.4, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), phpstan group.
- Composer refresh: `symfony/workflow` 7.4.20, `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4 (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.6.11]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.11

## [1.6.10] - 2026-09-28

### Security

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set. `RoleBasedWorkflowUiAccessChecker` denies access when given no roles.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.6.9] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.6.10]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.10
[1.6.9]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.9

## [1.6.8] - 2026-09-25

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `DatabaseWorkflowRegistry` now memoizes built workflows only for the current main request (and implements `ResetInterface`), so definitions edited or disabled in one worker are seen by every worker on the next request. Outside of an HTTP request (CLI, Messenger) nothing is memoized.
- `DatabaseMetadataStore` copies labels and workflow metadata at construction instead of keeping the `WorkflowDefinition` entity.
- `WorkflowApplicator` and `WorkflowDefinitionController` reset a closed EntityManager (through `ManagerRegistry`) after a failed `flush()` before rethrowing, so a worker does not keep a closed manager for later requests.
- PHPStan level 8 clean with `src/Form` and `src/Entity` included (Form `@extends` generics; entity `$id` visibility for ORM-assigned ids).
- Demo FrankenPHP stack restored to **PostgreSQL** (`pdo_pgsql`) to match the Dockerfile and `.env.example`; `release-verify` now fails if demo `up` fails.

### Documentation

- [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md): remediation for W-01–W-03; baseline spec **FR-WF-007** / **FR-DB-003**.
- [UPGRADING.md](UPGRADING.md), [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md), [USAGE.md](USAGE.md).

### Notes

- New optional constructor arguments (`?RequestStack` on `DatabaseWorkflowRegistry`, `?ManagerRegistry` on `WorkflowApplicator` and `WorkflowDefinitionController`) are autowired; no configuration changes.

[1.6.8]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.8

## [1.6.7] - 2026-08-26

### Added

- **REQ-UI-001:** `CssFramework` / `IconSet` PHP enums for config validation; CDN assets extracted to `_framework_assets.css.twig` / `_framework_assets.js.twig`; optional layout bridge `@NowoWorkflowBundle/layout_integrate_base.html.twig`.

### Changed

- Demo layout uses semantic `nowo-ui-*` hooks and loads framework CSS/JS via the extracted partials (Bootstrap 4 keeps its own CDN).
- **Docs:** CONTRIBUTING notes phpstan-frankenphp as maintainer-only (REQ-CS-005).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.6.7]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.7

## [1.6.6] - 2026-08-24

### Changed

- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.6.6]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.6

## [1.6.5] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_workflow.yaml` access rules (REQ-SEC-004). Prefer **`^1.6.5`** over `v1.6.4`.

## [1.6.4] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for workflow routes (REQ-SEC-004).

## [1.6.3] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.6.3]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.3

## [1.6.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.6.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.2

## [1.6.1] - 2026-08-07

### Fixed

- **CI / Composer:** regenerate `composer.lock` content-hash after the v1.6.0 `composer.json` changes (FormKit, UiKit, Twig Extra, Twig-CS-Fixer). `composer validate --strict` on the default branch is green again (REQ-CI-003).

### Tests

- Cover `prependFormKitDefaults` / `prependUiKitDefaults` (seed, host override, non-array UiKit config bags) so line coverage stays at 100%.

[1.6.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `workflow` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Import `@NowoUiKitBundle/macros/ui.html.twig`; removed local `_ui_macros.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit` from `ui.css_framework` / `icon_set` when the host has not configured UiKit.

[1.6.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.0

## [1.5.2] - 2026-07-29

### Added

- `make coverage-check` (100% clover gate) and `scripts/check-coverage.php` (REQ-TEST-006).
- `docs/SECURITY.md` 12.4.1 row for REQ-SEC-004 Pass (conditional).

### Changed

- `release-check` no longer runs `cs-fix`; uses `coverage-check`.
- PHPStan: `ignoreErrors: []` (REQ-CS-006); Form paths excluded for generics noise.
- Packagist homepage + keywords (`php`, `frankenphp`, `symfony-bundle`).
- Root `docker-compose.yml` fixed IPAM subnet (Docker address-pool exhaustion on dense hosts).
- `SchemaSyncService::listExistingSequenceNames` maps Doctrine `Sequence` objects only (DBAL 4).

## [1.5.1] - 2026-07-27

### Added

- REQ-MAKE-003: demo aggregate Makefile aliases `up` / `down` / `update-bundle`
- REQ-REL-003: `make check-open-prs` (included in `release-check`)
- REQ-DEMO-001: Symfony DebugBundle in the FrankenPHP demo (`require-dev`)
- Demo `release-verify` HTTP smoke (boot → HTTP 2xx/3xx → down)

### Documentation

- [RELEASE.md](RELEASE.md): open-PR gate and demo smoke in the pre-release pipeline
- [UPGRADING.md](UPGRADING.md): notes for 1.5.1

## [1.5.0] - 2026-07-27

### Breaking

- **Web UI private by default (REQ-UI-002):** the CRUD UI is no longer open by default. Without `symfony/security-bundle`, container compilation **fails** unless you set `nowo_workflow.security.allow_unauthenticated: true` (local demos/tests only) or provide `security.access_checker`. With SecurityBundle, `RoleBasedWorkflowUiAccessChecker` is auto-wired from `security.access_roles` (default `ROLE_ADMIN`).
- Empty `security.access_roles` / `ui.required_roles` now means **no** bundle-level role check (previously denied all access).

### Added

- `ui.layout_template`, `ui.css_framework`, `ui.icon_set` (REQ-UI-001) with Twig globals and multi-framework `_ui_macros.html.twig`
- `ui.list_page_size` (default `20`) pagination for dashboard and definition index (`?page=`); list queries eager-load associations (REQ-PERF-001)
- Root `security` config: `access_roles`, `access_checker`, `allow_unauthenticated`
- `WorkflowUiSecurityPass` compile-time SecurityBundle guard
- `@NowoWorkflowBundle/base.html.twig` page shell with `{{ parent() }}` asset stacking and stable `nowo_ui_*` blocks
- Semantic `nowo-ui-*` CSS hooks on admin markup
- FrankenPHP PHPStan rules (`nowo-tech/phpstan-frankenphp`) and README worker-mode banner (REQ-CS-005 / REQ-DOCS-017)
- `.scrutinizer.yml` (REQ-CI-002)
- `make demo-smoke`, `make down-dev`; demo `setup` / `verify` targets

### Changed

- Pages extend configurable layout via `base.html.twig` instead of hard-coding the demo layout alone
- Flex recipe documents security defaults; Symfony 8 demo sets `allow_unauthenticated: true`
- README: Documentation link order (REQ-DOCS-002); Symfony badge `7.4 | 8.0 | 8.1+`
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005)
- Spec Kit baseline + code inventory updated for UI/security/pagination

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md), [SECURITY.md](SECURITY.md), [USAGE.md](USAGE.md), [UPGRADING.md](UPGRADING.md), [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)

## [1.4.3] - 2026-07-22

### Changed

- Demo FrankenPHP entrypoint extracted to `demo/symfony8/docker/entrypoint.sh`; runtime mode selected via `FRANKENPHP_MODE` (`classic` \| `worker`, default `worker`)
- PHP-CS-Fixer: `fully_qualified_strict_types.import_symbols` enabled (FQCN → `use` imports)
- GitHub Actions: `actions/checkout` v6 → v7
- Lockfile synced (`doctrine/dbal`, `doctrine/doctrine-bundle`)

### Fixed

- REQ-GIT-001 verification uses `git --no-replace-objects` so local `git replace` refs cannot hide Cursor co-author trailers from CI
- History rewrite script refuses a dirty working tree before rewriting
- REQ-GITIGNORE-002: ignore `.php-cs-fixer.cache` as a file (stop tracking the cache)

### Documentation

- [GITHUB_CI.md](GITHUB_CI.md): expanded REQ-GIT-001 operator guide (scope, why CI enforces it, replace-refs pitfall)
- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): document `FRANKENPHP_MODE` and standalone entrypoint

## [1.4.2] - 2026-07-16

### Changed

- Renamed CI requirements doc `docs/GITLAB_CI.md` → `docs/GITHUB_CI.md` and aligned content with GitHub Actions (REQ-GIT-001)

### Documentation

- [GITHUB_CI.md](GITHUB_CI.md): GitHub Actions CI requirements for REQ-GIT-001
- [README.md](../README.md), [CONTRIBUTING.md](CONTRIBUTING.md): updated links to `GITHUB_CI.md`

## [1.4.1] - 2026-07-15

### Added

- Contributor Covenant Code of Conduct (`CODE_OF_CONDUCT.md`)
- REQ-GIT-001: verification script, history cleanup script, and `commit-msg` hook to block Cursor `Co-authored-by` trailers
- CI job `git-hygiene` enforcing REQ-GIT-001 on push and pull requests
- Makefile targets `check-no-cursor-coauthor` and `strip-cursor-coauthor-from-history`; `release-check` now includes co-author verification

### Changed

- GitHub Actions: `actions/cache` v5 → v6
- Dev lockfiles synced (`friendsofphp/php-cs-fixer`, `rector/rector`)

### Documentation

- CI requirements doc for REQ-GIT-001 (shipped as `GITLAB_CI.md`; renamed to `GITHUB_CI.md` in 1.4.2)
- [CONTRIBUTING.md](CONTRIBUTING.md): Code of Conduct reference and git hooks workflow
- [RELEASE.md](RELEASE.md): post-tag co-author check reminder before push
- [README.md](../README.md): links to Code of Conduct and CI requirements
- `.gitignore`: ignore `.cursor/sandbox.json` (machine-specific local file)

## [1.4.0] - 2026-07-08

### Added

- GitHub Spec Kit integration (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`)
- Baseline specification and code inventory under `specs/001-baseline/` (100% coverage of production code in `src/`)
- [SPEC-KIT.md](SPEC-KIT.md) — operator manual for Spec Kit install, initialization, and maintainer workflow

### Documentation

- [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md): three-layer SDD model (Spec Kit baseline, product behavior, `REQ-*` traceability) and contributor workflow
- [README.md](../README.md): link to Spec Kit documentation

## [1.3.1] - 2026-07-07

### Fixed

- Integration tests on PHP 8.4+: enable Doctrine native lazy objects in `IntegrationEntityManagerFactory`

## [1.3.0] - 2026-07-07

### Added

- `RoleBasedWorkflowUiAccessChecker` — built-in `WorkflowUiAccessCheckerInterface` using Symfony `AuthorizationCheckerInterface` (grant if the user has any configured role)
- Translation catalogs for **German** (`de`), **Dutch** (`nl`), and **Portuguese** (`pt`)
- Configuration key `ui.required_roles` (default `ROLE_ADMIN`) — documents expected roles for Flex/recipe wiring
- Flex recipe snippet `config/services/nowo_workflow_security.yaml` (commented) to alias the role-based checker when `symfony/security-bundle` is installed

### Changed

- French UI translations refined (labels for workflow type, transitions, and form fields)

### Fixed

- Demo `symfony8` Makefile: define `COMPOSE` and `SERVICE_PHP` so `make update-deps` resolves the correct Docker Compose service

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md): `ui.required_roles` and shipped locale catalogs
- [SECURITY.md](SECURITY.md): built-in `RoleBasedWorkflowUiAccessChecker` and Flex recipe wiring
- [UPGRADING.md](UPGRADING.md): notes for upgrading to 1.3.0

## [1.2.0] - 2026-06-30

### Changed

- **PHP** minimum raised to **8.2** (was 8.1)
- **Symfony** support narrowed to **7.x and 8.x** (Symfony 6.4 removed)
- **`doctrine/doctrine-bundle`** constraint updated to `^2.13 || ^3.2.4` (`3.2.4+` required for Symfony 8; needs PHP 8.4+)

### Fixed

- CI matrix: Symfony 8 jobs use `doctrine-bundle` ^3.2.4 and `--dev` for test-only Symfony packages
- CI excludes Symfony 8 on PHP 8.2/8.3 (Doctrine Bundle 3.2.4 requires PHP 8.4+)

### Documentation

- [README.md](../README.md), [UPGRADING.md](UPGRADING.md), [CONTRIBUTING.md](CONTRIBUTING.md): updated compatibility matrix

## [1.1.0] - 2026-06-30

### Added

- Configurable `table_prefix` for Doctrine table and constraint names (default `workflow_`)
- `WorkflowUiAccessCheckerInterface` and `AllowAllWorkflowUiAccessChecker` to protect the CRUD UI without Symfony `access_control` (open access when no custom checker is registered)
- `WorkflowRegistryInterface` as extension point for workflow resolution (`DatabaseWorkflowRegistry` implements it)
- `SchemaSyncService::executeStatements()` for idempotent schema SQL execution with duplicate-object tolerance
- Integration tests for schema sync and the workflow definition repository
- Unit test suite expanded to ~100% PHP coverage

### Changed

- `WorkflowApplicator` now type-hints `WorkflowRegistryInterface` instead of `DatabaseWorkflowRegistry`
- Translation catalog files renamed to `NowoWorkflowBundle.<locale>.yaml` (translation domain unchanged)

### Documentation

- [SECURITY.md](SECURITY.md): CRUD UI protection guide with `WorkflowUiAccessCheckerInterface`
- [CONFIGURATION.md](CONFIGURATION.md): `table_prefix` and UI locale options
- [UPGRADING.md](UPGRADING.md): notes for upgrading to 1.1.0

## [1.0.0] - 2026-06-10

Initial stable release.

### Added

- Database-driven Symfony Workflow definitions with CRUD UI
- `WorkflowResolver`, `WorkflowApplicator`, and `DatabaseWorkflowRegistry`
- Match rules with multi-parameter resolution
- Demo application (Symfony 8, FrankenPHP, PostgreSQL)
- Flex recipe under `.symfony/recipe/nowo-tech/workflow-bundle/`
- Twig override support (`@NowoWorkflowBundle/...`) and translation domain `NowoWorkflowBundle`

### Documentation

- Full Nowo bundle standards alignment (Docker, CI, security, spec-driven docs)
