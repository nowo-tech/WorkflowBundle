# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
