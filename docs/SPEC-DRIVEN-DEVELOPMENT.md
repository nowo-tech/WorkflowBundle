# Spec-driven development

In this repository, **spec-driven development** has two layers:

1. **Product behavior** — database-driven Symfony Workflow definitions, CRUD UI, and runtime resolution (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md)).
2. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos when scripted flows need discoverability.

Tests and static analysis are the mechanical proof alongside this document.

---

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** to define workflows in the database **so that** I can change state machines without redeploying PHP config. |
| US-02 | **As an** admin user, **I want** a CRUD UI for definitions **so that** I can manage places and transitions visually. |
| US-03 | **As a** developer, **I want** match rules and priority resolution **so that** the correct workflow applies per subject context. |
| US-04 | **As a** developer, **I want** `WorkflowApplicator` **so that** I can apply transitions and persist marking on domain entities. |
| US-05 | **As a** maintainer, **I want** automated tests **so that** registry and resolver regressions are caught in CI. |

---

## Bundle functional scope

**Goal:** Persist Symfony Workflow definitions in Doctrine, expose admin UI, resolve and apply transitions at runtime.

**In scope:** CRUD UI, `DatabaseWorkflowRegistry`, `WorkflowResolver`, `WorkflowApplicator`, schema sync CLI, demo playgrounds.

**Out of scope:** Authorization for CRUD (host app responsibility), custom workflow guard logic beyond Symfony Workflow component.

---

## Validating the functional spec

- Run **`make release-check`** (cs-fix, cs-check, rector-dry, phpstan, test-coverage, demo healthchecks).
- PHPUnit under `tests/Unit` and `tests/Integration`.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-TWIG-001 | `TwigPathsPass`, `docs/USAGE.md` | Twig override registration and documentation |
| REQ-I18N-001 | `Resources/translations/`, `docs/USAGE.md` | Translation domain `NowoWorkflowBundle` |

---

## Relationship to Engram

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide checklist items. This document ties **product behavior**, **verification**, and local **`REQ-*`** habits.

---

## See also

- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
