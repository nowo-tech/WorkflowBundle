# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

- [GITLAB_CI.md](GITLAB_CI.md): CI requirements for GitLab mirrors (REQ-GIT-001 and parity with GitHub Actions)
- [CONTRIBUTING.md](CONTRIBUTING.md): Code of Conduct reference and git hooks workflow
- [RELEASE.md](RELEASE.md): post-tag co-author check reminder before push
- [README.md](../README.md): links to Code of Conduct and GitLab CI requirements
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
