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

## Symfony Flex recipe

When using Symfony Flex, the recipe at `.symfony/recipe/nowo-tech/workflow-bundle/0.1/` copies:

- `config/packages/nowo_workflow.yaml` — default bundle configuration

After install, run `nowo:workflow:sync-schema` and import routes as shown above.
