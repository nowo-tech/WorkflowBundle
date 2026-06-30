# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
