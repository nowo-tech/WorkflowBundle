- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `workflow`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

# Installation

**Requirements:** PHP 8.2+, Symfony 7.x or 8.x (Symfony 8 requires PHP 8.4+ and `doctrine/doctrine-bundle` ^3.2.4). See [UPGRADING.md](UPGRADING.md#compatibility).

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
- `config/services/nowo_workflow_security.yaml` — optional access-checker wiring notes
- `post-install.txt` — reminds you to set `access_roles` and host `security.access_control` for `^/workflow`

After install, run `nowo:workflow:sync-schema` and import routes as shown above. Never enable `security.allow_unauthenticated` in production; see [SECURITY.md](SECURITY.md).

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
