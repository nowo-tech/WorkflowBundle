# Feature Specification: WorkflowBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/workflow-bundle`  
**Configuration root**: `nowo_workflow`


Symfony bundle to **persist Workflow definitions in Doctrine**, provide admin CRUD UI, resolve workflows by match rules, and apply transitions via `WorkflowApplicator`.

---

## User Scenarios & Testing

Per SDD US-01–US-05: DB-backed definitions, CRUD UI with flow diagram, context match resolution, runtime applicator, schema sync CLI.

---

## Requirements

### Registry & runtime

- **FR-WF-001–006**: CRUD controller, `DatabaseWorkflowRegistry`, `WorkflowResolver`, `WorkflowApplicator`, `WorkflowDefinitionBuilder`, `WorkflowGraphPresenter`. `SubjectClass` matches definitions against runtime subjects (Doctrine proxies, subclasses) for `WorkflowResolver` (FR-WF-003).
- **FR-WF-007**: FrankenPHP / long-running worker safety (kernel not reset between requests): `DatabaseWorkflowRegistry` memoizes built workflows only for the current main request and implements `ResetInterface`; `DatabaseMetadataStore` snapshots labels (no entity retained); `ClosedEntityManagerResetter` reopens a closed EM after failed flushes in `WorkflowApplicator` and the CRUD controller. See [`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md).
- **FR-ENTITY-001**: Entities for definition, places, transitions, match rules.
- **FR-MDL-001**: `WorkflowType` enum; `CssFramework` / `IconSet` UI enums; `WorkflowContext` model.
- **FR-API-001**: Registry/resolver contracts for host apps.

### Admin UI & forms

- **FR-FORM-001**: Nested form types for places, transitions, match rules with collection manager JS partial.
- **FR-VIEW-007**: Workflow editor/show templates and flow diagram partial; pages extend `@NowoWorkflowBundle/base.html.twig` with `nowo_ui_*` blocks and `{{ parent() }}` asset stacking.
- **FR-UI-001**: Configurable `ui.layout_template`, `ui.css_framework` (`bootstrap5`, `tailwind`, `foundation`, `custom`, …), `ui.icon_set`; framework CDN partials; semantic `nowo-ui-*` classes (UiKit macros).
- **FR-I18N-001 / FR-I18N-002**: Locale switcher controller and `LocaleManager`.
- **FR-SEC-007**: Private-by-default UI access via `security.access_roles` / optional `access_checker` / `allow_unauthenticated` (demo only); `WorkflowUiSecurityPass` + `RoleBasedWorkflowUiAccessChecker` / `AllowAllWorkflowUiAccessChecker`. Default alias `DenyAllWorkflowUiAccessChecker` is fail-closed.
- **FR-SEC-008**: With `web_ui.enabled: true` the container refuses to compile without CSRF protection (`security.csrf.token_manager`, form CSRF extension enabled) — `CsrfProtectionPass`, fail closed.
- **FR-UI-002**: `WorkflowPageFactory` builds the UiKit view models of the admin pages (REQ-UI-003); the edit page is a UiKit settings page with one tab per section (general, match rules, places, transitions).
- **FR-UI-003**: `Resources/public/js/nowo-workflow.js` / `css/nowo-workflow.css` — admin UI behaviour and styles without framework JS, inline handlers or `confirm()` (CSP-safe).
- **FR-UI-004**: Vendored Tom Select 2.4.3 (`Resources/public/vendor/tom-select/`, with its LICENSE) for the searchable from/to place selects; optional at runtime (native `<select multiple>` fallback), never loaded from a CDN.
- **FR-VIEW-008**: Flow diagram: `WorkflowGraphLayout` computes a layered (Sugiyama-style) layout; place / transition dialogs use the UiKit `dialog` partial.
- **FR-VIEW-009** (2.3, lazy dialogs): the show page prints ONE empty UiKit `remote_form_dialog` (`_flow_dialogs.html.twig`) instead of one dialog per place and transition. Each state and transition of the diagram is a link to its own page (`nowo_workflow_definition_place` / `_transition`, `GET /{id}/places/{index}` and `/{id}/transitions/{index}`) that answers the fragment (`_flow_place` / `_flow_transition`) for `X-Nowo-Dialog: 1` and the full page (`flow_detail.html.twig`, template key `flow_detail`, UiKit `page/show`) otherwise, with exactly the show page's tenant scope and `View` access decision; an unknown index is a 404. `WorkflowGraphPresenter::present()` carries `definitionId` for the links.

### Infrastructure

- **FR-BUNDLE-001 / FR-CFG-001 / FR-CFG-002**: Bundle, table prefix config, extension (`nowo_workflow` tree including `ui.*` and `security.*`).
- **FR-DB-001 / FR-DB-002**: Table prefix subscriber and metadata store.
- **FR-DB-003**: `ClosedEntityManagerResetter` for worker-safe recovery after a failed Doctrine flush.
- **FR-CLI-002 / FR-CLI-003**: Demo seed and schema sync commands.
- **FR-TWIG-001**: Workflow Twig extension globals (`nowo_workflow_layout_template`, `nowo_workflow_css_framework`, `nowo_workflow_icon_set`, locales).

### Versions 1.13 to 1.19

- **FR-WF-008**: Database workflows are built with the application `event_dispatcher` and dispatch the standard Symfony workflow events (`workflow.<slug>.guard.<transition>`, `leave`, `transition`, `enter`, `entered`, `completed`, `announce`), so host guard listeners run (1.13.0). `WorkflowApplicator::apply*()` turns a refused transition into `WorkflowTransitionBlockedException` carrying the blocker messages and codes (FR-ERR-002).
- **FR-WF-009**: A database workflow slug may not equal the name of a workflow configured under `framework.workflows`: `FrameworkWorkflowNamesPass` collects those names and `DatabaseWorkflowRegistry` refuses a colliding slug with `WorkflowNameCollisionException` (FR-ERR-002).
- **FR-SHAPE-001**: Shape rules of stored transitions as FrameworkBundle enforces them (at least one `from` and one `to` place, each listed once; a `state_machine` transition has exactly one `to` place). `WorkflowShapeValidator` is applied by the editor and by `WorkflowVersioning::publish()`; `WorkflowInvalidShapeException` reports a violation; 1.16.0 refuses to build such workflows (1.15.2 only warned).
- **FR-CLI-004**: `nowo:workflow:lint` (`LintCommand`) lists stored definitions, every tenant and version, whose transitions violate FR-SHAPE-001; exit code 1 when one is found.
- **FR-ERR-002**: Further domain exceptions: `WorkflowTransitionBlockedException` (extends `InvalidArgumentException`), `WorkflowNameCollisionException`, `WorkflowInvalidShapeException`, `WorkflowDefinitionInUseException`, `WorkflowPinConflictException`, `WorkflowStructureLockedException`.
- **FR-DI-003**: Compiler passes added since 1.14: `PrivateEntityManagerPass` (private manager wiring and validation of `doctrine.entity_manager`), `WorkflowRuntimePass` (runtime port wiring, host runtime wins, deprecated registry port kept as SPI), `FrameworkWorkflowNamesPass` (FR-WF-009).
- **FR-DI-004** (2.0, REQ-ARCH-001): `RemovedPortsPass` fails the build naming the service(s) and the replacing port when the application still registers, aliases or decorates a port removed in 2.0 (ids are strings, the interfaces no longer exist), including classes that cannot be loaded because they implement a removed interface.

### Private entity manager and persistence (1.14 to 1.16)

- **FR-DB-004**: The definitions are written through a bundle-private Doctrine entity manager (`WorkflowEntityManager`, internal, REQ-ARCH-001 rule 6) created on the source manager's connection, configuration and event manager, so saving a definition never flushes the application's pending changes. `WorkflowManagerRegistry` resolves the definition entities to it, `PrivateEntityManagerMiddleware` / `PrivateEntityManagerDriver` / `PrivateEntityManagerConnection` report rolled-back transactions and savepoints so a stale identity map is replaced, `PrivateManagerRepositoryFactory` keeps repositories per manager so replaced managers are collected, `WorkflowUnitOfWorkSubscriber` drops it at every outermost unit-of-work boundary (request, Messenger message, console command), and `PrivateEntityManagerPass` wires and validates it (options `doctrine.entity_manager`, `doctrine.private_entity_manager`).
- **FR-DB-005**: Exact identity keys (REQ-SEC-006): `BinaryKeyCollationSubscriber` gives the key columns (`tenant_key`, `slug`, `workflow_slug`, `subject_ref`) a binary NO PAD collation on MySQL / MariaDB when the schema is generated; `WorkflowExactKeyColumns` declares them to the MigrationsKit key-collation check.
- **FR-DB-006**: `UnprefixedTablesGuard` stops schema generation when `table_prefix` is set but the bundle tables still have their default names (DoctrineBundle 3 hosts on 1.15.0 / 1.15.1) and lists the `RENAME` statements to run first.
- **FR-DB-007**: XML mapping of the entities (`Resources/config/doctrine/*.orm.xml`, same schema as the attributes; options `doctrine.mapping_type`, `doctrine.register_mapping`).

### Access policy value objects (1.14)

- **FR-ACL-001**: Framework-free Workflow UI access port (REQ-ARCH-001 rule 3): `WorkflowUiAccessPolicyInterface::allows()` takes a `WorkflowUiAccessCheck` (method, route, controller, path, route parameters, `WorkflowActor`) built at the HTTP edge by `WorkflowUiAccessCheckFactory`; `WorkflowActor` / `WorkflowUiAccessCheck` keep request-derived values out of their properties through `Internal\HiddenValues`, so no dump or serialization shows them. The deprecated `WorkflowUiAccessCheckerInterface` stays the SPI behind it.

### Runtime port and pins (1.15, 1.16)

- **FR-RT-001**: Framework-free runtime port `WorkflowRuntimeInterface` (`can()`, `apply()`, `enabledTransitions()`, `marking()`) with `WorkflowTransitionResult` and `WorkflowMarkingView`; default `WorkflowRuntime` is `WorkflowApplicator` behind scalars and bundle value objects; `WorkflowRegistryInterface` is deprecated as a host port. `WorkflowSlugResolverInterface` gives the slug for a subject or context.
- **FR-RT-002**: `WorkflowRuntimeInterface::pin()` (1.16) pins a subject to the version it would start with, without transition or flush; `WorkflowPinMode` (`KeepExisting`, `Repin`) and `WorkflowPinResult` describe the outcome.

### Versioning and tenancy (1.15 to 1.19)

- **FR-VER-001**: Definition versions: `WorkflowDefinitionStatus` (published, draft, retired); with tenancy a published version is never edited, the editor works on a draft and `WorkflowVersioning` publishes it, clones a global template into a tenant, retires and reinstates a clone, and deletes drafts or a whole scope. A published definition with pinned subjects cannot be deleted (`WorkflowDefinitionInUseException`).
- **FR-VER-002**: Framework-free versioning port `WorkflowVersionManagerInterface` (1.19, REQ-ARCH-001 rule 1), twin of `WorkflowVersioningInterface`: takes a `WorkflowDefinitionRef` and returns a `WorkflowDefinitionView`, implemented by `WorkflowVersionManager` over the versioning SPI.
- **FR-VER-003**: In-flight subjects keep the version they started with: `WorkflowSubjectPin` rows (tenant, slug, opaque subject reference) are written by `SubjectPinStore` with plain DBAL statements, with concurrent first applies resolved to one version (`WorkflowPinConflictException` when the winning pin cannot be read).
- **FR-VER-005** (2.2, REQ-RUNTIME-003 rule 4): the next version number of a scope (tenant key and slug) is claimed from its counter row (`WorkflowDefinitionSequence`, table `workflow_definition_sequence`) by `DefinitionVersionSequence` with an atomic `UPDATE … SET last_version = last_version + 1`, in the transaction that inserts the version (`WorkflowDefinitionRepository::insertNextVersion()`, transaction-doctrine runner, READ COMMITTED for the bundle's own units on MySQL / MariaDB); never `MAX(version)+1` (`nextVersion()` is deprecated). The counter row is seeded at the scope's first claim from the stored versions (primary key decides) and moved up, never down, when it fell behind; the scope is re-checked under the claim (one draft per scope); contention answers a retryable conflict (409, `CONFLICT_001`); numbers are unique and increasing, never reused while the scope has versions, possibly with gaps; an existing counter is checked with a plain read so a claim conflict answers `CONFLICT_001` (ZI-L1); `binlog_format` STATEMENT keeps the session level (ZI-L2).
- **FR-VER-004**: `tenancy.lock_clone_structure` (1.16): a tenant clone keeps the global template's structure (initial place, places, transitions); `CloneStructureLock` enforces it and `WorkflowStructureLockedException` reports a violation.
- **FR-CAT-001** (2.1, DDD-host checklist B7, REQ-RUNTIME-002): framework-free catalog port `WorkflowDefinitionCatalogInterface` (default `WorkflowDefinitionCatalog`, separate from `WorkflowVersionManagerInterface`) lists a tenant's definitions as read models (`WorkflowDefinitionPage`) or references and installs a global template for a tenant (`WorkflowTemplateInstallation`: the tenant's definition and whether this call created it) with scalars, value objects and views only, never the aggregate or a Doctrine type; the install is idempotent and race-safe (the unique `tenant_key, slug, version` key arbitrates, the loser answers the winner's definition); the service holds no state between calls.
- **FR-TEN-001**: Opt-in multitenancy (`tenancy.tenant_resolver`): definitions belong to a tenant or are global templates; `WorkflowDefinitionLocator` picks the pinned version, else the tenant's current published version, else the global template, never another tenant's; `TenancyContext` asks the host ports (`WorkflowTenantResolverInterface`, `WorkflowSubjectReferenceResolverInterface`, `WorkflowActorProviderInterface`, default `SecurityTokenActorProvider`) on every call with `WorkflowTenant`, `WorkflowSubject`, `WorkflowSubjectRef` and `WorkflowActorRef`; `TenantIsolationGuardSubscriber` blocks any transition of a workflow not built from the version the subject runs.
- **FR-TEN-002**: Embeddable per-tenant UI: `web_ui.path_prefix` may hold route variables (`WorkflowUiRouteContextSubscriber` keeps them in every link), `WorkflowUiTenancy` scopes every controller action, host ports `WorkflowUiTenantResolverInterface` and `WorkflowUiActionCheckerInterface` (`WorkflowUiAction`, `WorkflowUiActionCheck`) decide scope and action (deny by default), and `WorkflowUiScope` drives row actions and badges.

### Guards, audit and labels (1.15, 1.16)

- **FR-GUARD-001**: Per-transition guards set in the editor, enforced by `TransitionGuardSubscriber`: a `permission` decided by the host's `WorkflowTransitionPermissionCheckerInterface` (`WorkflowTransitionPermissionCheck`), a `condition` evaluated over the host's whitelisted scalars (`WorkflowSubjectVariablesProviderInterface`, `WorkflowSubjectVariables`), and automatic transitions; a missing port or a throwing guard blocks (fail closed).
- **FR-EXPR-001**: Condition language: `GuardExpression` is a closed DSL (not Symfony ExpressionLanguage) over scalar variables only, with no function, method, property, index or arithmetic and bounded size and depth; `GuardExpressionException` reports a condition that cannot be parsed or evaluated.
- **FR-EVT-002**: `WorkflowTransitionAppliedEvent` (ids only, no entity or framework type) is dispatched once the transaction that persisted the subject committed (`audit.enabled`, on by default with tenancy); `TransitionAuditSubscriber` records it for every transition a database workflow completes.
- **FR-AUD-001**: After-commit dispatch: `AuditMiddleware`, `AuditDriver` and `AuditConnection` (DBAL 4) report transactions and savepoints to `AfterCommitDispatcher` (`TransactionObserverInterface`), which drops events of rolled-back or never committed work and dispatches the rest at a safe point, never from `postFlush`.
- **FR-I18N-005**: Translatable place and transition labels (1.16): `LabelTranslationTrait` adds `label_key` / `label_domain`, `WorkflowLabelTranslator` resolves them with fallback to the plain label then the name, and `LabelTranslationConstraints` validates the editor fields against `tenancy.label_domains`.

---

## Non-goals

- Executing workflows on behalf of the host beyond Symfony Workflow semantics (no job scheduling, no BPMN engine).
- Host authentication / user management (UI access is delegated to `security.*` / an access checker; deny-all by default).
- `demo/` trees (illustrative, not Packagist API).

---

## Future specifications

None at this time. Planned work must be added here (or under `FUT-*` IDs) with explicit **Status: future** — never as unmarked current `FR-*`.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (**182/182**, audited 2026-11-27 on 2.3.0).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `git ls-files src \| wc -l` (all tracked files under `src/`, no `*.test.ts` exist) |
| Demo smoke | `make demo-smoke` |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
