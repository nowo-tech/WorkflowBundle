# Upgrade Guide

## General process

1. Back up `config/packages/nowo_workflow.yaml`
2. Read [CHANGELOG.md](CHANGELOG.md) for breaking changes
3. Run `composer update nowo-tech/workflow-bundle`
4. Run `php bin/console nowo:workflow:sync-schema`
5. Clear cache: `php bin/console cache:clear`

## Upgrading to 1.1.0

From 1.0.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console nowo:workflow:sync-schema
php bin/console cache:clear
```

No database migration is required when keeping the default `table_prefix: workflow_` (same physical table names as 1.0.0).

### Optional: protect the CRUD UI

Register a custom `WorkflowUiAccessCheckerInterface` implementation. Without it, the UI remains open (same as 1.0.0). See [SECURITY.md](SECURITY.md#protecting-the-crud-ui).

### Optional: custom table prefix

If you set `table_prefix` to a value other than `workflow_`, run `nowo:workflow:sync-schema` after changing config. Existing installations should keep the default unless you intentionally rename tables.

### Translation overrides

If you override bundle translations, ensure files are named `NowoWorkflowBundle.<locale>.yaml` (not `nowo_workflow.<locale>.yaml`). The translation domain is still **`NowoWorkflowBundle`**.

### Custom registry or tests

`WorkflowApplicator` now depends on `WorkflowRegistryInterface`. Custom implementations or test doubles should implement that interface; `DatabaseWorkflowRegistry` remains the default service.

## Upgrading to 1.0.0

Initial release. Install with:

```bash
composer require nowo-tech/workflow-bundle
```

Register routes and sync schema as described in [INSTALLATION.md](INSTALLATION.md).

### Translation domain

UI translations use domain **`NowoWorkflowBundle`**. Override in `translations/NowoWorkflowBundle.<locale>.yaml`.

### Twig templates

Override under `templates/bundles/NowoWorkflowBundle/` (see [USAGE.md](USAGE.md)).

## Compatibility

| Bundle | Symfony | PHP |
|--------|---------|-----|
| 1.x | 6.3, 7.x, 8.x | 8.1 – 8.5 |
