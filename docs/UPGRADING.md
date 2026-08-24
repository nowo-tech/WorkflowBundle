# Upgrading

## Table of contents

- [From 1.6.5 to 1.6.6](#from-165-to-166)

## From 1.6.5 to 1.6.6

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/workflow-bundle
```

## From 1.6.5 to 1.6.6

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/workflow-bundle
```

# Upgrade Guide

## General process

1. Back up `config/packages/nowo_workflow.yaml`
2. Read [CHANGELOG.md](CHANGELOG.md) for breaking changes
3. Run `composer update nowo-tech/workflow-bundle`
4. Run `php bin/console nowo:workflow:sync-schema`
5. Clear cache: `php bin/console cache:clear`

## Upgrading to 1.6.5

Review Flex recipe `security_nowo_workflow.yaml` after update.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.4

Review Flex recipe `security_nowo_workflow.yaml` access rules after install/update.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.3

No application upgrade steps.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.2

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Continue requiring `nowo-tech/workflow-bundle` as before.

## Upgrading to 1.6.1

From 1.6.0 — maintainer/CI fix only (`composer.lock` content-hash + prepend coverage). No host migration.

```bash
composer update nowo-tech/workflow-bundle
```

## Upgrading to 1.6.0

From 1.5.2 — FormKit admin forms, UiKit macros, Twig Extra (REQ-TWIG-004), Twig-CS-Fixer.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
php bin/console assets:install --symlink --relative public
```

Requires `nowo-tech/form-kit-bundle` ^2.0 and `nowo-tech/ui-kit-bundle` ^1.4. Hosts that render Twig templates need `twig/extra-bundle` + `twig/string-extra` (usually via Flex).

## Upgrading to 1.5.2

From 1.5.1:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or consumer code update is required. This patch tightens maintainer QA (`coverage-check`), PHPStan (`ignoreErrors: []`), Packagist metadata, Docker IPAM, and DBAL 4 sequence listing in `SchemaSyncService`.

## Upgrading to 1.5.1

From 1.5.0:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or consumer code update is required. This patch completes maintainer/demo standards: demo Makefile aliases (`make -C demo up`), open-PR gate in `release-check`, Symfony DebugBundle in the FrankenPHP demo, and HTTP smoke in `demo release-verify`.

## Upgrading to 1.5.0

From 1.4.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

See [CHANGELOG.md](CHANGELOG.md) for the full list. Summary for integrators:

### Breaking — Web UI private by default (REQ-UI-002)

- The CRUD UI is **no longer open by default**. Without `symfony/security-bundle`, container compilation **fails** unless you set `nowo_workflow.security.allow_unauthenticated: true` (local demos/tests only) or provide `security.access_checker`.
- With SecurityBundle, `RoleBasedWorkflowUiAccessChecker` is wired automatically using `security.access_roles` (default `ROLE_ADMIN`).
- `ui.required_roles` remains as a **BC alias** of `security.access_roles`.
- Empty `access_roles` / `required_roles` now means **no** bundle-level role check (previously denied everyone).
- Protect `ui.path` (default `/workflow`) with Symfony `access_control` in the host app.

**Minimal production config:**

```yaml
nowo_workflow:
    ui:
        path: '/workflow'
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/workflow, roles: ROLE_ADMIN }
```

### Web UI look-and-feel (REQ-UI-001)

New keys under `ui`:

| Key | Default |
| --- | ------- |
| `layout_template` | `@NowoWorkflowBundle/layout.html.twig` |
| `css_framework` | `bootstrap5` |
| `icon_set` | `bootstrap-icons` |
| `list_page_size` | `20` |

Pages now extend `@NowoWorkflowBundle/base.html.twig` and stack assets with `{{ parent() }}`. Set `layout_template` to your project layout. Dashboard and definition index lists are paginated (`?page=`); set `list_page_size: 0` only for small demos. See [CONFIGURATION.md](CONFIGURATION.md).

### Twig blocks

Stable blocks: `nowo_ui_content`, `nowo_ui_styles`, `nowo_ui_scripts`, `nowo_ui_page_header`, `nowo_ui_modals`, `nowo_ui_flashes`. Renaming these later is a breaking change.

### Recipe / demo

- Flex recipe YAML documents the new defaults.
- The Symfony 8 demo intentionally sets `security.allow_unauthenticated: true` (no SecurityBundle). Do **not** copy that into production.

## Upgrading to 1.4.3

From 1.4.2:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required for Symfony integrators. This release improves maintainer CI/git hygiene, PHP-CS-Fixer import rules, and the FrankenPHP demo (`FRANKENPHP_MODE`). Bundle consumers who do not run `demo/` are unaffected.

If you maintain a local clone of the demo, set `FRANKENPHP_MODE=classic` (or `worker`) in `demo/symfony8/.env` and recreate containers after pulling.

## Upgrading to 1.4.2

From 1.4.1:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release only renames and refines maintainer CI documentation (`GITHUB_CI.md`); Symfony integrators are unaffected.

## Upgrading to 1.4.1

From 1.4.0:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release adds maintainer-only git hygiene tooling, Code of Conduct, and CI/documentation updates; Symfony integrators are unaffected.

## Upgrading to 1.4.0

From 1.3.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration, configuration change, or code update is required. This release adds maintainer-only Spec Kit scaffolding and baseline documentation; Symfony integrators are unaffected.

## Upgrading to 1.3.0

From 1.2.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

No database migration is required.

### Optional: role-based CRUD UI protection

If you use **Symfony Security**, you can alias the built-in checker instead of writing a custom class:

```yaml
# config/services/nowo_workflow_security.yaml
services:
    Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface:
        class: Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker
        arguments:
            $requiredRoles: ['ROLE_ADMIN']
            $authorizationChecker: '@security.authorization_checker'
```

New Flex installs receive this file from the recipe (commented until `symfony/security-bundle` is installed). The `ui.required_roles` config key documents the intended roles but does **not** auto-register the checker — you must alias the service (or use `access_control`).

### Optional: enable new UI locales

Catalogs for `de`, `nl`, and `pt` ship with the bundle. Add them to `ui.locales` to show them in the locale switcher:

```yaml
nowo_workflow:
    ui:
        locales: [en, es, fr, it, de, nl, pt]
```

## Upgrading to 1.2.0

From 1.1.x:

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

### Breaking: PHP and Symfony

| Requirement | 1.1.x | 1.2.0 |
|-------------|-------|-------|
| PHP | 8.1+ | **8.2+** |
| Symfony | 6.3, 7.x, 8.x | **7.x, 8.x only** |
| Symfony 8 + Doctrine | — | PHP **8.4+** and `doctrine/doctrine-bundle` **^3.2.4** |

If you run **Symfony 6.4** or **PHP 8.1**, stay on `1.1.x` until you upgrade the host application.

For **Symfony 8**, ensure the app uses `doctrine/doctrine-bundle` ^3.2.4 (requires PHP 8.4+). Symfony 7 apps can keep `doctrine-bundle` ^2.13.

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
| 1.x | 7.x (PHP 8.2+), 8.x (PHP 8.4+) | 8.2 – 8.5 |
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `workflow` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

## Unreleased

## To 1.6.0

From **1.5.2** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

