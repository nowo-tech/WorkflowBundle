# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/workflow-bundle`  
**Last audited**: 2026-10-20

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Compiler/WorkflowUiSecurityPass.php` | UI security wiring | FR-SEC-007 |
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

## Contracts & attributes

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Contract/WorkflowContextAwareInterface.php` | Public contract | FR-API-001 |
| `Contract/WorkflowRegistryInterface.php` | Public contract | FR-API-001 |
| `Contract/WorkflowUiAccessCheckerInterface.php` | Public contract | FR-API-001 |

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
| `Resources/views/_locale_switcher.html.twig` | Shared partial template | FR-VIEW-010 |
| `Resources/views/base.html.twig` | Page shell + parent() stacking | FR-UI-001, FR-VIEW-001 |
| `Resources/views/dashboard/index.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/layout_integrate_base.html.twig` | Optional host layout bridge | FR-UI-001 |
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

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 5 | 5 |
| CLI | 2 | 2 |
| Controllers | 3 | 3 |
| Persistence | 5 | 5 |
| Forms | 7 | 7 |
| Domain models | 4 | 4 |
| Application services | 14 | 14 |
| Contracts & attributes | 3 | 3 |
| Twig PHP & page views | 2 | 2 |
| Persistence integration | 5 | 5 |
| Exceptions | 2 | 2 |
| Symfony config | 2 | 2 |
| Translations | 7 | 7 |
| Twig views | 12 | 12 |
| Public assets (JS/CSS, vendored Tom Select) | 6 | 6 |
| **Total production sources** | **79** | **79** |

Audit: `git ls-files src | wc -l` → 79.
