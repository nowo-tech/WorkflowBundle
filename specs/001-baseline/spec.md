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
**Configuration root**: `workflow`


Symfony bundle to **persist Workflow definitions in Doctrine**, provide admin CRUD UI, resolve workflows by match rules, and apply transitions via `WorkflowApplicator`.

---

## User Scenarios & Testing

Per SDD US-01–US-05: DB-backed definitions, CRUD UI with flow diagram, context match resolution, runtime applicator, schema sync CLI.

---

## Requirements

### Registry & runtime

- **FR-WF-001–006**: CRUD controller, `DatabaseWorkflowRegistry`, `WorkflowResolver`, `WorkflowApplicator`, `WorkflowDefinitionBuilder`, `WorkflowGraphPresenter`.
- **FR-ENTITY-001**: Entities for definition, places, transitions, match rules.
- **FR-MDL-001**: `WorkflowType` enum; `WorkflowContext` model.
- **FR-API-001**: Registry/resolver contracts for host apps.

### Admin UI & forms

- **FR-FORM-001**: Nested form types for places, transitions, match rules with collection manager JS partial.
- **FR-VIEW-007**: Workflow editor/show templates and flow diagram partial.
- **FR-I18N-001 / FR-I18N-002**: Locale switcher controller and `LocaleManager`.
- **FR-SEC-007**: UI access checker (allow-all and role-based implementations).

### Infrastructure

- **FR-BUNDLE-001 / FR-CFG-001 / FR-CFG-002**: Bundle, table prefix config, extension.
- **FR-DB-001 / FR-DB-002**: Table prefix subscriber and metadata store.
- **FR-CLI-002 / FR-CLI-003**: Demo seed and schema sync commands.
- **FR-TWIG-001**: Workflow extension helpers.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (65/65 mapped).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan, Vitest where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' \| wc -l` |
| TS tests | `pnpm test` or `make test-ts` (when assets present) |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
