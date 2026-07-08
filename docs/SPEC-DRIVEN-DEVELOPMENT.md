# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — database-driven Symfony Workflow definitions, CRUD UI, and runtime resolution (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos when scripted flows need discoverability.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

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

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---


## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## Relationship to Engram

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide checklist items. This document ties **product behavior**, **verification**, and local **`REQ-*`** habits.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
