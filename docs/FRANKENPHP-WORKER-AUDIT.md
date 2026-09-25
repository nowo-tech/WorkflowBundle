# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/workflow-bundle` (`symfony-bundle`) |
| Audited revision | `v1.6.8` |
| Audit date | 2026-09-25 |
| Method | Manual review of every file under `src/` (services, controllers, subscribers, Twig extension, form types, commands, Doctrine subscriber, DI extension, compiler passes, `Resources/config/services.yaml`); entities and value objects skimmed |
| Remediation (2026-09-23 / release 1.6.8) | W-01 to W-03 resolved: request-scoped workflow memo + `ResetInterface` on `DatabaseWorkflowRegistry`, entity-free `DatabaseMetadataStore`, closed-EntityManager recovery after failed flushes (`Doctrine\ClosedEntityManagerResetter`) in `WorkflowApplicator` and `WorkflowDefinitionController`; regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — no bundle-owned state outlives the main request; definitions edited in one worker are seen by every worker on the next request. Clearing Doctrine's identity map between requests remains the application's responsibility (see usage recommendations) |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ Resolved | `DatabaseWorkflowRegistry` memo is bound to the current main request (`WeakReference`) and dropped on the next one; every other service is stateless |
| Static properties / `static` locals | ✅ | None; only pure static helpers and static closures |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `DatabaseWorkflowRegistry` implements `ResetInterface` (autoconfigured `kernel.reset`) for scenario A; scenario B does not depend on it |
| Request / user / locale captured in services | ✅ | `LocaleManager` reads the session from `RequestStack` on every call; access checker calls `AuthorizationChecker` per request |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ Resolved | Built workflows no longer reference entities; failed flushes in `WorkflowApplicator` / `WorkflowDefinitionController` reset the closed manager via `ManagerRegistry` before rethrowing |
| Output, headers, `exit`, shutdown functions | ✅ | None; controllers return `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Registry memo is bounded by the slugs used in one request and released with it |
| Blocking I/O and timeouts | ✅ | Only Doctrine queries; no HTTP or process calls |
| Third-party static state | ✅ | Symfony Workflow objects are built with `null` dispatcher and a stateless `MethodMarkingStore` |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` (note: `src/Form/*` and `src/Entity/*` are excluded from analysis) |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Service\DatabaseWorkflowRegistry` (also aliased as `Contract\WorkflowRegistryInterface`) | yes | `array $cache` of built workflows scoped to the current main request (`WeakReference` owner); `reset()` clears it | ✅ | ✅ |
| `Service\WorkflowApplicator` | yes | none (`readonly` deps); resets a closed EM after a failed flush | ✅ | ✅ |
| `Service\WorkflowResolver` | yes | none | ✅ | ✅ |
| `Service\WorkflowDefinitionBuilder` | yes | none | ✅ | ✅ |
| `Service\WorkflowGraphPresenter` | yes | none (pure functions over the entity passed in) | ✅ | ✅ |
| `Service\LocaleManager` | yes | none; reads session through `RequestStack` per call | ✅ | ✅ |
| `Service\AllowAllWorkflowUiAccessChecker` / `Service\RoleBasedWorkflowUiAccessChecker` | yes | none (`final readonly`, roles from config) | ✅ | ✅ |
| `Service\SchemaSyncService`, `Service\DemoSeedService` | yes | none | ✅ | ✅ |
| `EventListener\LocaleSubscriber`, `EventListener\WorkflowUiAccessSubscriber` | yes | none | ✅ | ✅ |
| `Twig\WorkflowExtension` | yes | none; globals are config values | ✅ | ✅ |
| `Doctrine\TableNamePrefixer`, `Doctrine\TablePrefixSubscriber` | yes | none; only runs on `loadClassMetadata` | ✅ | ✅ |
| `Repository\WorkflowDefinitionRepository` | yes | none beyond `ServiceEntityRepository` | ✅ | ✅ |
| 3 controllers (`DashboardController`, `LocaleController`, `WorkflowDefinitionController`) | yes | none (`readonly` deps); `WorkflowDefinitionController` resets a closed EM after a failed flush | ✅ | ✅ |
| `Doctrine\ClosedEntityManagerResetter` | static helper | none | ✅ | ✅ |
| 6 form types + `PlaceChoiceHelper` | yes | none; listeners are static closures attached per form build | ✅ | ✅ |
| 2 commands (`SyncSchemaCommand`, `SeedDemoCommand`) | CLI only | none | N/A | N/A |

`Service\DatabaseMetadataStore` is not used as a container service: it is created with `new` in `src/Service/WorkflowDefinitionBuilder.php:40` and wraps one `WorkflowDefinition` entity. `Model\WorkflowContext` is a `final readonly` value object.

## Findings

### W-01 — Workflow registry cache is never reset and is per worker (Medium)

- **Where:** `src/Service/DatabaseWorkflowRegistry.php:22-43` (`private array $cache`, filled in `get()`); invalidation only in `invalidate()` (`:56-65`), called from `src/Controller/WorkflowDefinitionController.php:63`, `:131`, `:149-150` and `src/Service/DemoSeedService.php:35`, `:44`. The class does not implement `ResetInterface`.
- **Worker impact:** in classic mode the cache lives for one request. In worker mode (A and B) each worker keeps the built `Workflow` / `StateMachine` for a slug until the process ends. When an admin edits places or transitions, only the worker that handled the edit calls `invalidate()`; every other worker keeps applying the **old** transitions through `WorkflowApplicator::apply*()` / `getEnabledTransitions*()` (`src/Service/WorkflowApplicator.php:94`, `:108`, `:119`). Direct users of `WorkflowRegistryInterface::get()` / `has()` also keep getting a workflow that was disabled or deleted afterwards, because the `isEnabled()` check at `:38` runs only on a cache miss. This is stale business data, not a cross-user leak; if transitions are used as an approval / authorization gate, treat it as higher severity.
- **Recommendation:** implement `ResetInterface` on `DatabaseWorkflowRegistry` with `reset(): void { $this->cache = []; }` (fixes A), and for B validate the cached entry against the definition's `updatedAt` (already stored in `src/Entity/WorkflowDefinition.php:93-94`) or drop the cache, since `WorkflowApplicator` already loads the definition from the database on every call.
- **Status:** Resolved — `src/Service/DatabaseWorkflowRegistry.php` implements `ResetInterface` and takes an optional `RequestStack`; the memo is owned by the current main request (held through a `WeakReference`) and dropped as soon as another main request is seen, so each request rebuilds from the database. Sub-requests reuse the main request's memo; outside of an HTTP request nothing is memoized. `updatedAt` was not used as a version key because edits to child places/transitions do not touch it. Tests: `tests/Unit/Service/DatabaseWorkflowRegistryTest.php` (`testSecondRequestWithoutResetSeesDefinitionChangedByAnotherWorker`, `testSecondRequestWithoutResetSeesDefinitionDisabledByAnotherWorker`, sub-request / no-request / `reset()` cases).

### W-02 — Cached workflows hold an entity from an earlier request (Low)

- **Where:** `src/Service/WorkflowDefinitionBuilder.php:40` passes the `WorkflowDefinition` entity to `new DatabaseMetadataStore($definition)`, which keeps it (`src/Service/DatabaseMetadataStore.php:18-21`); the resulting workflow is cached by W-01.
- **Worker impact:** after Doctrine clears the EntityManager between requests (A) the entity is detached, so labels and metadata returned by the metadata store stay frozen at the first build. Collections `places` and `transitions` are already initialized during the build (`getPlaceNames()` and `getTransitions()` in `WorkflowDefinitionBuilder::build()`), so no lazy-loading error is expected. Memory held is one entity graph per slug.
- **Recommendation:** fixed together with W-01. Alternatively copy the needed labels into arrays inside `DatabaseMetadataStore` instead of keeping the entity.
- **Status:** Resolved — besides W-01, `src/Service/DatabaseMetadataStore.php` now copies workflow metadata and place/transition labels into arrays in the constructor and keeps no entity reference (constructor signature unchanged). Test: `DatabaseMetadataStoreTest::testMetadataIsSnapshottedAndDoesNotFollowLaterEntityChanges`.

### W-03 — `WorkflowApplicator` depends on Doctrine's resetter after a failed flush (Medium, scenario B only)

- **Where:** `src/Service/WorkflowApplicator.php:100-101` (`$workflow->apply()` followed by `$this->entityManager->flush()`).
- **Worker impact:** if `flush()` throws (constraint violation, deadlock), the EntityManager is closed. Under A, DoctrineBundle's `doctrine` registry (tagged `kernel.reset`) resets the closed manager before the next request. Under B the closed manager is kept and every later request that touches Doctrine in that worker fails with "EntityManager is closed". This is generic Doctrine behaviour, not specific to this bundle.
- **Recommendation:** keep `services_resetter` enabled (scenario A). No bundle change required.
- **Status:** Resolved — new `src/Doctrine/ClosedEntityManagerResetter.php` resets the closed manager through `ManagerRegistry::resetManager()` (looked up by identity in `getManagers()`); `WorkflowApplicator` (new optional `?ManagerRegistry` argument) calls it when `flush()` throws and rethrows the original exception. The same guard was added to the three `flush()` calls in `src/Controller/WorkflowDefinitionController.php` (e.g. duplicate slug on create — there is no `UniqueEntity` constraint, so the unique index can close the manager). The bundle never calls `clear()` on the application's manager. Tests: `WorkflowApplicatorTest::testFailedFlushResetsClosedEntityManagerSoNextRequestCanFlush`, `WorkflowDefinitionControllerTest::testNewPostResetsClosedEntityManagerWhenFlushFails`, `tests/Unit/Doctrine/ClosedEntityManagerResetterTest.php`.

No other findings. `LocaleManager` does not keep the resolved locale in a property, and the UI access checkers ask the authorization checker on every request, so no user data can leak between requests.

## Usage recommendations in worker mode

- Workers no longer need a restart after editing definitions: every request rebuilds workflows from the database.
- Under scenario B, clearing Doctrine's identity map between requests remains the application's responsibility (the bundle never calls `EntityManager::clear()`): an entity already loaded by an earlier request in the same worker is returned as-is by `findOneBy()` and would carry stale fields. Keeping `services_resetter` active (scenario A) handles this.
- Custom `WorkflowUiAccessCheckerInterface` or `WorkflowRegistryInterface` implementations must stay stateless (or implement `ResetInterface`); never cache the current user or request in them.
- The demo (`demo/symfony8`) runs in worker mode by default (`FRANKENPHP_MODE=worker`, `docker/Caddyfile` has a `worker` block); use it to check that an edit is visible from all workers.

## Re-audit triggers

Re-run this audit when a change adds: new properties or caches to `DatabaseWorkflowRegistry`, `WorkflowResolver` or `WorkflowApplicator`, an event dispatcher or listeners attached to built workflows, a custom marking store with state, per-request data stored in `LocaleManager` or the access checkers, or any use of `$_SERVER` / `$_ENV` at runtime.
