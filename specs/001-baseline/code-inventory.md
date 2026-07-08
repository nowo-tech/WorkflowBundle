# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/workflow-bundle`  
**Last audited**: 2026-07-07

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
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
| `Controller/WorkflowDefinitionController.php` | Workflow CRUD controller | FR-WF-001 |

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
| `Enum/WorkflowType.php` | Domain enum | FR-MDL-001 |
| `Model/WorkflowContext.php` | Domain model | FR-MDL-002 |

## Application services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `EventListener/LocaleSubscriber.php` | Domain events | FR-EVT-001 |
| `EventListener/WorkflowUiAccessSubscriber.php` | Domain events | FR-EVT-001 |
| `Service/AllowAllWorkflowUiAccessChecker.php` | Allow-all UI access | FR-SEC-007 |
| `Service/DatabaseWorkflowRegistry.php` | DB workflow registry | FR-WF-002 |
| `Service/DemoSeedService.php` | Demo seed data | FR-CLI-002 |
| `Service/LocaleManager.php` | UI locale manager | FR-I18N-002 |
| `Service/RoleBasedWorkflowUiAccessChecker.php` | Role-based UI access | FR-SEC-007 |
| `Service/WorkflowApplicator.php` | Transition applicator | FR-WF-004 |
| `Service/WorkflowDefinitionBuilder.php` | Definition builder from entities | FR-WF-005 |
| `Service/WorkflowGraphPresenter.php` | Flow diagram presenter | FR-WF-006 |
| `Service/WorkflowResolver.php` | Workflow match resolver | FR-WF-003 |

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

## Persistence integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/TableNamePrefixer.php` | Persistence integration | FR-DB-001 |
| `Doctrine/TablePrefixSubscriber.php` | Persistence integration | FR-DB-001 |
| `Service/DatabaseMetadataStore.php` | DB metadata introspection | FR-DB-002 |
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
| `Resources/views/dashboard/index.html.twig` | Dashboard template | FR-VIEW-003 |
| `Resources/views/layout.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/workflow_definition/_collection_manager.js.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/_edit_nav.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/_flow_diagram.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/_match_rules_section.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/_places_section.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/_transitions_section.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/form.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/index.html.twig` | Workflow editor template | FR-VIEW-007 |
| `Resources/views/workflow_definition/show.html.twig` | Workflow editor template | FR-VIEW-007 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 4 | 4 |
| CLI | 2 | 2 |
| Controllers | 3 | 3 |
| Persistence | 5 | 5 |
| Forms | 7 | 7 |
| Domain models | 2 | 2 |
| Application services | 11 | 11 |
| Contracts & attributes | 3 | 3 |
| Twig PHP | 1 | 1 |
| Persistence integration | 4 | 4 |
| Exceptions | 2 | 2 |
| Symfony config | 2 | 2 |
| Translations | 7 | 7 |
| Twig views | 12 | 12 |
| **Total production sources** | **65** | **65** |

Audit: `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l`
