# Workflow Bundle

[![CI](https://github.com/nowo-tech/WorkflowBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/WorkflowBundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/nowo-tech/workflow-bundle/v/stable)](https://packagist.org/packages/nowo-tech/workflow-bundle)
[![License](https://poser.pugx.org/nowo-tech/workflow-bundle/license)](https://packagist.org/packages/nowo-tech/workflow-bundle)
[![PHP Version Require](https://poser.pugx.org/nowo-tech/workflow-bundle/require/php)](https://packagist.org/packages/nowo-tech/workflow-bundle)
[![Symfony](https://img.shields.io/badge/Symfony-6.3%20|%207%20|%208-brightgreen)](composer.json)
[![Coverage](manual)](docs/CONTRIBUTING.md#tests)

Define Symfony Workflow state machines and workflows in the database, manage them with a built-in CRUD UI, and execute transitions at runtime through `DatabaseWorkflowRegistry` and `WorkflowApplicator`.

## Features

- Persist workflow definitions (places, transitions, subject class, marking property) in Doctrine
- Built-in admin UI at `/workflow` (configurable path)
- Runtime resolution via `DatabaseWorkflowRegistry` (Symfony Workflow component)
- `WorkflowApplicator` helper to apply transitions and flush subjects
- Demo with order approval (state machine) and document review (parallel workflow)

## Requirements

- PHP >= 8.1 < 8.6
- Symfony 6.3+, 7.x, or 8.x
- Doctrine ORM

## Installation

```bash
composer require nowo-tech/workflow-bundle
```

Register the bundle (Flex recipe when available):

```php
// config/bundles.php
Nowo\WorkflowBundle\NowoWorkflowBundle::class => ['all' => true],
```

Import routes:

```yaml
# config/routes.yaml
nowo_workflow:
    resource: '@NowoWorkflowBundle/Resources/config/routes.yaml'
```

Configure:

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    enabled: true
    ui:
        path: '/workflow'
```

Sync schema:

```bash
php bin/console nowo:workflow:sync-schema
php bin/console nowo:workflow:seed-demo   # optional demo definitions
```

## Usage

Inject the registry or applicator in your services:

```php
use Nowo\WorkflowBundle\Service\WorkflowApplicator;

public function approve(object $order, WorkflowApplicator $applicator): void
{
    $applicator->apply($order, 'order_approval', 'approve');
}
```

Your subject entity must expose the configured marking property (e.g. `status` with getter/setter).

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
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)

## Tests

```bash
make test
make test-coverage
```

PHP coverage target: high (see CI).

## Found this useful?

If this bundle helps your project, consider starring the repository or opening an issue with feedback.

## License

MIT — see [LICENSE](LICENSE).
