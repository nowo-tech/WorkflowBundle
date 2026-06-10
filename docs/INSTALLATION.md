# Installation

```bash
composer require nowo-tech/workflow-bundle
```

## Bundle registration

```php
Nowo\WorkflowBundle\NowoWorkflowBundle::class => ['all' => true],
```

## Routes

```yaml
nowo_workflow:
    resource: '@NowoWorkflowBundle/Resources/config/routes.yaml'
```

## Database

```bash
php bin/console nowo:workflow:sync-schema
```

## Demo data

```bash
php bin/console nowo:workflow:seed-demo
```

See [demo/README.md](../demo/README.md) for the FrankenPHP playground.
