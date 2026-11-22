# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/workflow-bundle`  
**Last audited**: 2026-11-22 (v2.1.1)

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/WorkflowUiSecurityPass.php` | UI security wiring | FR-SEC-007 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | CSRF fail-closed when the UI is enabled | FR-SEC-008 |
| `DependencyInjection/Compiler/RemovedPortsPass.php` | Fails the build on a service still wired to a port removed in 2.0 | FR-DI-004 |
| `DependencyInjection/Configuration.php` | Config tree (`ui.*`, `security.*`) | FR-CFG-001, FR-UI-001, FR-SEC-007 |
| `DependencyInjection/NowoWorkflowExtension.php` | DI extension | FR-CFG-002 |
| `NowoWorkflowBundle.php` | Bundle entry | FR-BUNDLE-001 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/SeedDemoCommand.php` | CLI demo seed | FR-CLI-002 |
| `Command/SyncSchemaCommand.php` | CLI schema sync | FR-CLI-003 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/DashboardController.php` | Dashboard controller | FR-DASH-001 |
| `Controller/LocaleController.php` | Locale switch controller | FR-I18N-001 |
| `Controller/WorkflowDefinitionController.php` | Workflow CRUD controller (closed-EM recovery) | FR-WF-001, FR-WF-007 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/WorkflowDefinition.php` | Persistence model | FR-ENTITY-001 |
| `Entity/WorkflowMatchRule.php` | Persistence model | FR-ENTITY-001 |
| `Entity/WorkflowPlace.php` | Persistence model | FR-ENTITY-001 |
| `Entity/WorkflowTransition.php` | Persistence model | FR-ENTITY-001 |
| `Repository/WorkflowDefinitionRepository.php` | Repository implementation | FR-REPO-002 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/PlaceChoiceHelper.php` | Symfony form type | FR-FORM-001 |
| `Form/PlaceMultiSelectType.php` | Symfony form type | FR-FORM-001 |
| `Form/WorkflowDefinitionFormSection.php` | Symfony form type | FR-FORM-001 |
| `Form/WorkflowDefinitionFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/WorkflowMatchRuleType.php` | Symfony form type | FR-FORM-001 |
| `Form/WorkflowPlaceType.php` | Symfony form type | FR-FORM-001 |
| `Form/WorkflowTransitionType.php` | Symfony form type | FR-FORM-001 |

## Domain models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/CssFramework.php` | UI config enum | FR-MDL-001 / FR-UI-001 |
| `Enum/IconSet.php` | UI config enum | FR-MDL-001 / FR-UI-001 |
| `Enum/WorkflowType.php` | Domain enum | FR-MDL-001 |
| `Model/WorkflowContext.php` | Domain model | FR-MDL-002 |

## Application services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `EventListener/LocaleSubscriber.php` | Domain events | FR-EVT-001 |
| `EventListener/WorkflowUiAccessSubscriber.php` | Domain events | FR-EVT-001 |
| `Service/AllowAllWorkflowUiAccessChecker.php` | Allow-all UI access | FR-SEC-007 |
| `Service/DatabaseWorkflowRegistry.php` | DB workflow registry (request-scoped memo + `ResetInterface`) | FR-WF-002, FR-WF-007 |
| `Service/DemoSeedService.php` | Demo seed data | FR-CLI-002 |
| `Service/LocaleManager.php` | UI locale manager | FR-I18N-002 |
| `Service/RoleBasedWorkflowUiAccessChecker.php` | Role-based UI access | FR-SEC-007 |
| `Service/WorkflowApplicator.php` | Transition applicator (closed-EM recovery) | FR-WF-004, FR-WF-007 |
| `Service/WorkflowDefinitionBuilder.php` | Definition builder from entities | FR-WF-005 |
| `Service/WorkflowGraphPresenter.php` | Flow diagram presenter | FR-WF-006 |
| `Service/WorkflowResolver.php` | Workflow match resolver | FR-WF-003 |
| `Service/DenyAllWorkflowUiAccessChecker.php` | Fail-closed default UI access (deny all) | FR-SEC-007 |
| `Service/WorkflowGraphLayout.php` | Layered (Sugiyama-style) layout of the flow diagram | FR-VIEW-008 |
| `Util/SubjectClass.php` | Subject class matching (Doctrine proxies, subclasses) | FR-WF-003 |
| `Concurrency/SubjectMarkingGuard.php` | Expected-marking compare-and-set of a subject (1.20, REQ-RUNTIME-003) | FR-WF-004 |
| `Concurrency/WorkflowConcurrencyPolicy.php` | Per-workflow concurrency control (1.20) | FR-WF-004 |
| `EventListener/WorkflowConflictResponseSubscriber.php` | Lock conflict answered as 409 / CONFLICT codes (1.20) | FR-WF-004, FR-EVT-001 |
| `Bridge/SearchKit/WorkflowRequestSearchDefinition.php` | SearchKit group of workflow requests (1.21) | FR-ACL-001, FR-API-001 |
| `Search/WorkflowRequestSearchCriteria.php` | Conditions a search narrower adds (1.21) | FR-ACL-001, FR-API-001 |
| `Search/WorkflowRequestSearchOptions.php` | Validated options of one search subject (1.21) | FR-ACL-001, FR-API-001 |

## Contracts & attributes

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Contract/WorkflowContextAwareInterface.php` | Public contract | FR-API-001 |
| `Contract/WorkflowSlugResolverInterface.php` | Public port: slug a subject or context runs (1.17, REQ-ARCH-001 rule 1) | FR-API-001 |

## Twig PHP

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/WorkflowExtension.php` | Twig extension | FR-TWIG-001 |
| `View/WorkflowPageFactory.php` | UiKit admin page view models (REQ-UI-003) | FR-UI-002 |

## Persistence integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/ClosedEntityManagerResetter.php` | Closed EM recovery (worker mode) | FR-DB-003, FR-WF-007 |
| `Doctrine/TableNamePrefixer.php` | Persistence integration | FR-DB-001 |
| `Doctrine/TablePrefixSubscriber.php` | Persistence integration | FR-DB-001 |
| `Service/DatabaseMetadataStore.php` | DB metadata snapshot (no entity retained) | FR-DB-002, FR-WF-007 |
| `Service/SchemaSyncService.php` | Schema sync | FR-CLI-003 |

## Exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Exception/WorkflowAmbiguousMatchException.php` | Domain exception | FR-ERR-001 |
| `Exception/WorkflowNotFoundException.php` | Domain exception | FR-ERR-001 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/routes.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoWorkflowBundle.de.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.en.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.es.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.fr.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.it.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.nl.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoWorkflowBundle.pt.yaml` | i18n messages | FR-I18N-004 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/dashboard/index.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/workflow_definition/_flow_diagram.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/form.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/index.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/show.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/edit.html.twig` | Edit page (UiKit page/settings, one tab per section) | FR-VIEW-007, FR-UI-002 |
| `Resources/views/workflow_definition/_general_fields.html.twig` | General fields grid | FR-VIEW-007 |
| `Resources/views/workflow_definition/_collection_section.html.twig` | Collection editor (match rules, places, transitions) with UiKit dialog | FR-VIEW-007, FR-FORM-001 |
| `Resources/views/workflow_definition/_flow_dialogs.html.twig` | Place / transition dialogs of the flow diagram | FR-VIEW-008 |

## Public assets

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/public/js/nowo-workflow.js` | Admin UI behaviour (no framework JS, no inline handlers) | FR-UI-003 |
| `Resources/public/css/nowo-workflow.css` | Admin UI styles on UiKit tokens | FR-UI-003 |
| `Resources/public/vendor/tom-select/tom-select.complete.min.js` | Vendored Tom Select (searchable selects) | FR-UI-004 |
| `Resources/public/vendor/tom-select/tom-select.default.min.css` | Vendored Tom Select styles | FR-UI-004 |
| `Resources/public/vendor/tom-select/LICENSE` | Third-party license shipped with the vendored copy | FR-UI-004 |
| `Resources/public/vendor/tom-select/README.md` | Vendored copy provenance / version notes | FR-UI-004 |

## Doctrine private entity manager

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/WorkflowEntityManager.php` | Bundle-private entity manager for the definitions (1.14) | FR-DB-004 |
| `Doctrine/WorkflowManagerRegistry.php` | Doctrine registry resolving definition entities to the private manager | FR-DB-004 |
| `Doctrine/PrivateEntityManagerMiddleware.php` | DBAL middleware reporting rollbacks to the private manager | FR-DB-004 |
| `Doctrine/PrivateEntityManagerDriver.php` | Driver wrapper of the private manager's connection (internal) | FR-DB-004 |
| `Doctrine/PrivateEntityManagerConnection.php` | Reports rolled-back transactions, savepoints and failed commits (internal) | FR-DB-004 |
| `Doctrine/PrivateManagerRepositoryFactory.php` | Per-manager repository factory (worker-mode memory) | FR-DB-004, FR-WF-007 |
| `EventListener/WorkflowUnitOfWorkSubscriber.php` | Drops the private manager at each unit-of-work boundary | FR-DB-004, FR-WF-007 |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Private manager wiring and `doctrine.entity_manager` validation | FR-DB-004, FR-DI-003 |
| `Doctrine/BinaryKeyCollationSubscriber.php` | Binary NO PAD collation of the key columns | FR-DB-005 |
| `Collation/WorkflowExactKeyColumns.php` | Identity key columns declared for the REQ-SEC-006 check | FR-DB-005 |
| `Doctrine/UnprefixedTablesGuard.php` | Schema generation guard for unprefixed tables | FR-DB-006 |
| `Resources/config/doctrine/WorkflowDefinition.orm.xml` | XML mapping (parity with attributes) | FR-DB-007 |
| `Resources/config/doctrine/WorkflowMatchRule.orm.xml` | XML mapping (parity with attributes) | FR-DB-007 |
| `Resources/config/doctrine/WorkflowPlace.orm.xml` | XML mapping (parity with attributes) | FR-DB-007 |
| `Resources/config/doctrine/WorkflowSubjectPin.orm.xml` | XML mapping (parity with attributes) | FR-DB-007, FR-VER-003 |
| `Resources/config/doctrine/WorkflowTransition.orm.xml` | XML mapping (parity with attributes) | FR-DB-007 |
| `Entity/WorkflowSubjectPin.php` | Persisted version pin of a subject | FR-VER-003 |
| `Entity/LabelTranslationTrait.php` | Translatable label columns of places and transitions | FR-I18N-005 |

## Ports (host contracts)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Contract/WorkflowRuntimeInterface.php` | Framework-free runtime port | FR-RT-001, FR-RT-002, FR-API-001 |
| `Contract/WorkflowVersionManagerInterface.php` | Framework-free versioning port (1.19) | FR-VER-002, FR-API-001 |
| `Contract/WorkflowUiAccessPolicyInterface.php` | Framework-free UI access port (1.14) | FR-ACL-001, FR-API-001 |
| `Contract/WorkflowTenantResolverInterface.php` | Host port: tenant of a subject | FR-TEN-001, FR-API-001 |
| `Contract/WorkflowSubjectReferenceResolverInterface.php` | Host port: opaque subject id for pins | FR-TEN-001, FR-VER-003, FR-API-001 |
| `Contract/WorkflowActorProviderInterface.php` | Host port: actor applying transitions | FR-TEN-001, FR-API-001 |
| `Contract/WorkflowUiTenantResolverInterface.php` | Host port: tenant scope of a UI request | FR-TEN-002, FR-API-001 |
| `Contract/WorkflowUiActionCheckerInterface.php` | Host port: view / edit / publish authorisation | FR-TEN-002, FR-API-001 |
| `Contract/WorkflowTransitionPermissionCheckerInterface.php` | Host port: permission guard of a transition | FR-GUARD-001, FR-API-001 |
| `Contract/WorkflowSubjectVariablesProviderInterface.php` | Host port: whitelisted scalars for conditions | FR-GUARD-001, FR-EXPR-001, FR-API-001 |
| `Contract/WorkflowRequestSearchGateInterface.php` | Host port: gate of the request search (1.21) | FR-ACL-001, FR-API-001 |
| `Contract/WorkflowRequestSearchNarrowerInterface.php` | Host port: narrows the request search (1.21) | FR-ACL-001, FR-API-001 |
| `Contract/WorkflowRequestTitleResolverInterface.php` | Host port: titles of found requests (1.21) | FR-API-001 |
| `Contract/WorkflowDefinitionCatalogInterface.php` | Framework-free catalog port: list and install templates (2.1, B7) | FR-CAT-001, FR-API-001 |

## Model value objects

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Model/WorkflowActor.php` | Framework-free authenticated user of a UI request | FR-ACL-001 |
| `Model/WorkflowUiAccessCheck.php` | Framework-free view of a UI request | FR-ACL-001 |
| `Internal/HiddenValues.php` | Keeps request-derived values out of VO dumps | FR-ACL-001 |
| `Http/WorkflowUiAccessCheckFactory.php` | Builds the access VO from a Symfony request at the HTTP edge | FR-ACL-001 |
| `Model/WorkflowTransitionResult.php` | Outcome of `WorkflowRuntimeInterface::apply()` | FR-RT-001 |
| `Model/WorkflowMarkingView.php` | Marking of a subject (definition version and places) | FR-RT-001 |
| `Model/WorkflowPinResult.php` | Outcome of `WorkflowRuntimeInterface::pin()` | FR-RT-002 |
| `Enum/WorkflowPinMode.php` | Behaviour of `pin()` for an already pinned subject | FR-RT-002 |
| `Model/WorkflowDefinitionRef.php` | Reference to one stored definition version | FR-VER-002 |
| `Model/WorkflowDefinitionView.php` | Read-only snapshot of one definition version | FR-VER-002 |
| `Enum/WorkflowDefinitionStatus.php` | Version lifecycle (published, draft, retired) | FR-VER-001 |
| `Model/WorkflowTenant.php` | Opaque tenant key | FR-TEN-001 |
| `Model/WorkflowSubject.php` | Subject object and real class given to host ports | FR-TEN-001 |
| `Model/WorkflowSubjectRef.php` | Opaque subject identifier | FR-TEN-001, FR-VER-003 |
| `Model/WorkflowActorRef.php` | Opaque actor identifier | FR-TEN-001, FR-EVT-002 |
| `Model/WorkflowUiAction.php` | UI action kind (view, edit, publish) | FR-TEN-002 |
| `Model/WorkflowUiActionCheck.php` | One UI action to authorise | FR-TEN-002 |
| `Model/WorkflowTransitionPermissionCheck.php` | Permission guard check given to the host port | FR-GUARD-001 |
| `Model/WorkflowSubjectVariables.php` | Whitelisted scalar facts for conditions | FR-GUARD-001, FR-EXPR-001 |
| `Enum/WorkflowConcurrencyMode.php` | Concurrency mode of a transition apply (1.20) | FR-WF-004 |
| `Model/WorkflowRequestRef.php` | One request found by a search (1.21) | FR-ACL-001, FR-API-001 |
| `Model/WorkflowDefinitionPage.php` | One page of the catalog list: read models only (2.1) | FR-CAT-001 |
| `Model/WorkflowTemplateInstallation.php` | Outcome of a template install: the tenant's definition and `created` (2.1) | FR-CAT-001 |

## Versioning and tenancy services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/WorkflowRuntime.php` | Default runtime port over `WorkflowApplicator` | FR-RT-001 |
| `DependencyInjection/Compiler/WorkflowRuntimePass.php` | Runtime port wiring | FR-RT-001, FR-DI-003 |
| `Service/WorkflowVersioning.php` | Drafts, publish, clone, retire, delete | FR-VER-001 |
| `Service/WorkflowVersionManager.php` | Default versioning port over the versioning SPI | FR-VER-002 |
| `Service/WorkflowDefinitionCatalog.php` | Default catalog port: list and idempotent race-safe template install (2.1) | FR-CAT-001 |
| `Tenancy/CloneStructureLock.php` | Locks a tenant clone to the template structure | FR-VER-004 |
| `Tenancy/SubjectPinStore.php` | Reads and writes subject pins with plain DBAL | FR-VER-003 |
| `Tenancy/TenancyContext.php` | The host's runtime tenancy ports in one place | FR-TEN-001 |
| `Tenancy/WorkflowDefinitionLocator.php` | Picks the definition version a subject runs | FR-TEN-001 |
| `Tenancy/SecurityTokenActorProvider.php` | Default actor from the security token | FR-TEN-001 |
| `EventListener/TenantIsolationGuardSubscriber.php` | Blocks transitions of a workflow built from another version | FR-TEN-001 |
| `Http/WorkflowUiTenancy.php` | Tenant scope and per-action authorisation of UI controllers | FR-TEN-002 |
| `View/WorkflowUiScope.php` | Tenant scope of a UI page (row actions, badges) | FR-TEN-002 |
| `EventListener/WorkflowUiRouteContextSubscriber.php` | Route prefix variables kept in bundle links | FR-TEN-002 |
| `Service/WorkflowLabelTranslator.php` | Translated display labels with fallback | FR-I18N-005 |
| `Form/LabelTranslationConstraints.php` | Constraints of the label translation fields | FR-I18N-005 |

## Expression guards

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Expression/GuardExpression.php` | Closed condition DSL | FR-EXPR-001 |
| `Expression/GuardExpressionException.php` | Unparsable or unevaluable condition (fail closed) | FR-EXPR-001 |
| `EventListener/TransitionGuardSubscriber.php` | Enforces permission and condition guards | FR-GUARD-001 |

## Events and audit

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/WorkflowTransitionAppliedEvent.php` | Transition-applied audit event | FR-EVT-002 |
| `EventListener/TransitionAuditSubscriber.php` | Records the audit event of each completed transition | FR-EVT-002, FR-AUD-001 |
| `Audit/AfterCommitDispatcher.php` | Dispatches events after the persisting commit | FR-AUD-001 |
| `Audit/AuditMiddleware.php` | DBAL middleware reporting transactions | FR-AUD-001 |
| `Audit/AuditDriver.php` | Driver wrapper for the audit middleware (internal) | FR-AUD-001 |
| `Audit/AuditConnection.php` | Reports transactions and savepoints (internal) | FR-AUD-001 |
| `Audit/TransactionObserverInterface.php` | Transaction observer contract (internal) | FR-AUD-001 |
| `Event/WorkflowRequestAccessCheckEvent.php` | Host view decision on found requests (1.21) | FR-ACL-001, FR-EVT-001 |

## Commands and shape validation

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/LintCommand.php` | `nowo:workflow:lint` | FR-CLI-004, FR-SHAPE-001 |
| `Service/WorkflowShapeValidator.php` | Shape rules of stored transitions | FR-SHAPE-001 |
| `DependencyInjection/Compiler/FrameworkWorkflowNamesPass.php` | Collects `framework.workflows` names | FR-WF-009, FR-DI-003 |

## Further exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Exception/WorkflowTransitionBlockedException.php` | Transition exists but cannot be applied | FR-ERR-002, FR-WF-008 |
| `Exception/WorkflowNameCollisionException.php` | Slug equals a framework workflow name | FR-ERR-002, FR-WF-009 |
| `Exception/WorkflowInvalidShapeException.php` | Transition shape FrameworkBundle refuses | FR-ERR-002, FR-SHAPE-001 |
| `Exception/WorkflowDefinitionInUseException.php` | Delete refused while subjects are pinned | FR-ERR-002, FR-VER-001 |
| `Exception/WorkflowPinConflictException.php` | Concurrent pin that cannot be read yet | FR-ERR-002, FR-VER-003 |
| `Exception/WorkflowStructureLockedException.php` | Clone structure differs from the template | FR-ERR-002, FR-VER-004 |
| `Exception/WorkflowConflictException.php` | Concurrent apply conflict (1.20) | FR-WF-004, FR-ERR-002 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 7 | 7 |
| CLI | 2 | 2 |
| Controllers | 3 | 3 |
| Persistence | 5 | 5 |
| Forms | 7 | 7 |
| Domain models | 4 | 4 |
| Application services | 20 | 20 |
| Contracts & attributes | 2 | 2 |
| Twig PHP | 2 | 2 |
| Persistence integration | 5 | 5 |
| Exceptions | 2 | 2 |
| Symfony config | 2 | 2 |
| Translations | 7 | 7 |
| Twig views | 9 | 9 |
| Public assets | 6 | 6 |
| Doctrine private entity manager | 18 | 18 |
| Ports (host contracts) | 14 | 14 |
| Model value objects | 23 | 23 |
| Versioning and tenancy services | 16 | 16 |
| Expression guards | 3 | 3 |
| Events and audit | 8 | 8 |
| Commands and shape validation | 3 | 3 |
| Further exceptions | 7 | 7 |
| **Total production sources** | **175** | **175** |

Audit: `git ls-files src | wc -l` → 176.
