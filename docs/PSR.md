# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/workflow-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `psr/log` in `require`; `Psr\Log\LoggerInterface` is an optional constructor argument (default `NullLogger`, autowired to the host `logger`) of `SyncSchemaCommand`, `SeedDemoCommand` and `WorkflowDefinitionController` (schema sync, demo seeding and definition deletion are logged, REQ-OBS-001). Tests assert the records with a PSR-3 `AbstractLogger` double. |
| PSR-6 / PSR-16 Cache | **N/A** | No package-owned cache layer. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | The only HTTP surface is the Symfony admin UI (HttpFoundation controllers); a PSR-7 bridge would not help consumers. |
| PSR-18 HTTP client | **N/A** | No outbound HTTP client. |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection only. |
| PSR-14 Event dispatcher | **Already satisfied via Symfony** | The bundle dispatches no events of its own; Symfony Workflow dispatches its `workflow.*` events through Symfony's PSR-14 compatible dispatcher, and the bundle only subscribes to kernel events. No separate FIG dependency required. |
| PSR-15 HTTP middleware | **N/A** | No middleware pipeline: access and locale handling are Symfony kernel subscribers. |
| PSR-20 Clock | **N/A** | Time is only used for the `createdAt` / `updatedAt` audit timestamps of the definition entity (no TTL, token or scheduling logic). Re-evaluate if time-dependent behaviour is added. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger of the CLI commands and the definition delete action).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains logging, HTTP, cache, clock, or event SPIs.

---

_REQ-CS-007 evaluation date: 2026-10-29 (PSR-3 adopted with REQ-OBS-001; previous evaluation 2026-08-21)._
