# Workflow Bundle

[![CI](https://github.com/nowo-tech/WorkflowBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/WorkflowBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/workflow-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/workflow-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/workflow-bundle.svg)](https://packagist.org/packages/nowo-tech/workflow-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.0%2B%20%7C%208.0%2B-000000?logo=symfony)](https://symfony.com)
[![GitHub stars](https://img.shields.io/github/stars/nowo-tech/WorkflowBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/WorkflowBundle)
[![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

Define Symfony Workflow state machines and workflows in the database, manage them with a built-in CRUD UI, and execute transitions at runtime through `DatabaseWorkflowRegistry` and `WorkflowApplicator`.

> 📋 **Compatible with Symfony 7.x (PHP 8.2+) and 8.x (PHP 8.4+)**

## Features

- Persist workflow definitions (places, transitions, subject class, marking property) in Doctrine
- Configurable Doctrine `table_prefix` for table and constraint names
- Built-in admin UI at `/workflow` (configurable path), optionally protected via `WorkflowUiAccessCheckerInterface` or `RoleBasedWorkflowUiAccessChecker`
- UI translations for `en`, `es`, `fr`, `it`, `de`, `nl`, `pt` (enable extra locales in `ui.locales`)
- Runtime resolution via `DatabaseWorkflowRegistry` (Symfony Workflow component)
- `WorkflowApplicator` helper to apply transitions and flush subjects
- Demo with order approval (state machine) and document review (parallel workflow)
- FrankenPHP demo with PostgreSQL (see [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md))

## Quick start

```bash
composer require nowo-tech/workflow-bundle
```

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    enabled: true
    ui:
        path: '/workflow'
```

```bash
php bin/console nowo:workflow:sync-schema
```

## Demo

```bash
make -C demo up-symfony8
```

Open http://localhost:8022 — try the CRUD at `/workflow` and playgrounds for orders/documents.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

| Area | Coverage |
|------|----------|
| PHP | 100% |

```bash
make test
make test-coverage
```

## License

MIT — see [LICENSE](LICENSE).
