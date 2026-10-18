# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.9.0](#to-190)
  - [Template contract](#template-contract)
  - [Configuration](#configuration)
- [From 1.8.1 to 1.8.2](#from-181-to-182)
- [From 1.8.0 to 1.8.1](#from-180-to-181)
- [From 1.7.0 to 1.8.0](#from-170-to-180)
- [From 1.6.12 to 1.7.0](#from-1612-to-170)
- [From 1.6.11 to 1.6.12](#from-1611-to-1612)
- [From 1.6.10 to 1.6.11](#from-1610-to-1611)
- [From 1.6.9 to 1.6.10](#from-169-to-1610)
- [From 1.6.7 to 1.6.8](#from-167-to-168)
- [From 1.6.6 to 1.6.7](#from-166-to-167)
- [From 1.6.5 to 1.6.6](#from-165-to-166)
- [General process](#general-process)
- [Upgrading to 1.6.5](#upgrading-to-165)
- [Upgrading to 1.6.4](#upgrading-to-164)
- [Upgrading to 1.6.3](#upgrading-to-163)
- [Upgrading to 1.6.2](#upgrading-to-162)
- [Upgrading to 1.6.1](#upgrading-to-161)
- [Upgrading to 1.6.0](#upgrading-to-160)
- [Upgrading to 1.5.2](#upgrading-to-152)
- [Upgrading to 1.5.1](#upgrading-to-151)
- [Upgrading to 1.5.0](#upgrading-to-150)
  - [Breaking — Web UI private by default (REQ-UI-002)](#breaking--web-ui-private-by-default-req-ui-002)
  - [Web UI look-and-feel (REQ-UI-001)](#web-ui-look-and-feel-req-ui-001)
  - [Twig blocks](#twig-blocks)
  - [Recipe / demo](#recipe--demo)
- [Upgrading to 1.4.3](#upgrading-to-143)
- [Upgrading to 1.4.2](#upgrading-to-142)
- [Upgrading to 1.4.1](#upgrading-to-141)
- [Upgrading to 1.4.0](#upgrading-to-140)
- [Upgrading to 1.3.0](#upgrading-to-130)
  - [Optional: role-based CRUD UI protection](#optional-role-based-crud-ui-protection)
  - [Optional: enable new UI locales](#optional-enable-new-ui-locales)
- [Upgrading to 1.2.0](#upgrading-to-120)
  - [Breaking: PHP and Symfony](#breaking-php-and-symfony)
- [Upgrading to 1.1.0](#upgrading-to-110)
  - [Optional: protect the CRUD UI](#optional-protect-the-crud-ui)
  - [Optional: custom table prefix](#optional-custom-table-prefix)
  - [Translation overrides](#translation-overrides)
  - [Custom registry or tests](#custom-registry-or-tests)
- [Upgrading to 1.0.0](#upgrading-to-100)
  - [Translation domain](#translation-domain)
  - [Twig templates](#twig-templates)
- [Compatibility](#compatibility)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
- [Unreleased](#unreleased-1)
- [To 1.6.10](#to-1610)
- [To 1.6.9](#to-169)
- [To 1.6.0](#to-160)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## Unreleased

No configuration change. Hosts that override `@NowoWorkflowBundle/workflow_definition/_flow_diagram.html.twig`:
the branching diagram is now an SVG built from `graph.layout.diagram` (`nodes` with `x`/`y`/`width`/`height`,
`edges` with `path`, `labelLeft`/`labelTop`/`labelWidth`/`labelHeight`); the old flex markup (`__grid`, `__column`,
`__bridge`, `__spacer` classes and their CSS) is gone. `graph.layout.columns`, `bridges` and `backEdges` are still
provided (now from the corrected ranking: `backEdges` holds only true loops).

## To 1.9.0

From **1.8.2**:

```bash
composer update nowo-tech/workflow-bundle
```

From **1.8.2** — admin pages on the UiKit page contract (REQ-UI-003); requires `nowo-tech/ui-kit-bundle` **^2.1**.
Minor release: the old configuration keeps working (deprecated), but the page markup changed.

```bash
composer update nowo-tech/workflow-bundle nowo-tech/ui-kit-bundle -W
php bin/console assets:install
php bin/console cache:clear
```

### Template contract

What a host may notice:

| Page | Before | Now |
| --- | --- | --- |
| Dashboard | `dashboard/index.html.twig` on `base.html.twig`, paginated table | `page/dashboard` (`DashboardPage`): KPI tiles + definitions card (`nowo_ui_dashboard_cards`) |
| Definitions | `workflow_definition/index.html.twig`, local `_pagination` | `page/list` (`ListPage`): `nowo_ui_list_row`; row actions view / edit / **delete** (UiKit confirm `<dialog>`) |
| Detail | `workflow_definition/show.html.twig`, inline `confirm()` | `page/show` (`ShowPage`): `nowo_ui_show_sections` (diagram + sections), `nowo_ui_modals` (diagram dialogs) |
| New | `workflow_definition/form.html.twig` (`title`, `form`, `section`) | same file on `page/form` (`FormPage`): `nowo_ui_form_body` |
| Edit sections | `workflow_definition/form.html.twig` + `_edit_nav` | `workflow_definition/edit.html.twig` on `page/settings` (`SettingsPage`, tabs): `nowo_ui_settings_section` |

- Templates receive `page` (UiKit view model) instead of `definitions` / `list_*` / `title` / `form`; the show page
  still gets `definition` and `graph`, the edit page `definition` and `section`, the dashboard `definitions` and
  `total`.
- Removed templates: `layout.html.twig`, `_framework_assets.css.twig`, `_framework_assets.js.twig`,
  `_pagination.html.twig`, `workflow_definition/_edit_nav.html.twig`, `_match_rules_section.html.twig`,
  `_places_section.html.twig`, `_transitions_section.html.twig`, `_collection_manager.js.twig`.
- Dialogs are native `<dialog>`s: the diagram and collection editor dialogs render UiKit's `dialog` partial (slots
  `dialog`, `dialog_header`, `dialog_title`, `dialog_body`, `dialog_footer`; sizes `md` / `lg`) and open through
  `data-nowo-ui-dialog-open` (`nowo-ui-dialog.js`, loaded by UiKit layouts and by the show / edit pages); the delete
  confirmation uses the UiKit `confirm` partial and needs `nowo-ui-confirm.js` in your layout (UiKit layouts load
  it). Bootstrap JS is no longer needed. CSS aimed at `.nowo-workflow-dialog` or `[data-workflow-dialog]` must
  target the UiKit dialog slots (`nowo_ui_kit.theme.bundles.NowoWorkflowBundle`) instead.
- Form controls carry `nowo-ui-input nowo-ui-input--full` (UiKit 2.1 `input_full` slot; plus `form-control` on
  Bootstrap stacks); the bundle CSS no longer overrides `.nowo-ui-input`.
- Assets load from `bundles/nowoworkflow/` (run `assets:install`); Tom Select is bundled, no CDN.
- Forms no longer apply `bootstrap_5_layout.html.twig` on their own unless the deprecated `ui.css_framework` is set
  (then through `FormPage::$formThemes` / `SettingsSection::$formThemes`, not `form_theme` in the templates):
  configure `twig.form_themes` (or the FormKit `workflow` profile) for your stack.

Migrating overrides: delete copies under `templates/bundles/NowoWorkflowBundle/` and theme the view types once in
UiKit (`nowo_ui_kit.theme`, `templates/bundles/NowoUiKitBundle/page/*.html.twig`). For a Workflow-only change, use
`nowo_workflow.web_ui.templates.<page>` with a template that extends `@!NowoWorkflowBundle/…` and overrides a data
block ([TEMPLATES.md](TEMPLATES.md#per-page-overrides)).

### Configuration

| Deprecated (1.9, removed in 2.0) | New |
| --- | --- |
| `enabled` | `web_ui.enabled` |
| `ui.path` | `web_ui.path_prefix` |
| `ui.layout_template` | `web_ui.layout_template` (`null` = `nowo_ui_kit.layouts.admin`) |
| `ui.list_page_size` / `ui.default_locale` / `ui.locales` | `web_ui.list_page_size` / `web_ui.default_locale` / `web_ui.locales` |
| `ui.required_roles` | `security.access_roles` |
| `ui.css_framework` / `ui.icon_set` | `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set` |

```yaml
# Before
nowo_workflow:
    ui:
        path: '/workflow'
        layout_template: 'base.html.twig'
        css_framework: bootstrap5

# After
nowo_ui_kit:
    css_framework: bootstrap5
    layouts:
        admin: 'layouts/admin.html.twig'   # renders the UiKit canonical blocks (CONFIGURATION.md)
nowo_workflow:
    web_ui:
        path_prefix: '/workflow'
```

While `ui.layout_template` is set, pages render through the deprecated `@NowoWorkflowBundle/base.html.twig` bridge
into that layout (its `title`, `stylesheets`, `body` and `javascripts` blocks), as in 1.x. The removed default
`@NowoWorkflowBundle/layout.html.twig` maps to the UiKit layout.

## From 1.8.1 to 1.8.2

From **1.8.1** — Twig coding-standard cleanup; twig-lint in CI

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.8.0 to 1.8.1

From **1.8.0** — default layout no longer renders page content twice (form pages 500)

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.7.0 to 1.8.0

From **1.7.0** — fixes - Doctrine proxies/subclasses resolved, normalized context, scoped form themes, configured locales, Symfony >=7.4.

```bash
composer update nowo-tech/workflow-bundle
```

- **Requirements:** Symfony `^7.4 || ^8.0` (7.0–7.3 are no longer allowed) and `symfony/yaml` is now required at runtime.
- **Form themes:** the bundle no longer adds `bootstrap_5_layout.html.twig` / `bootstrap_4_layout.html.twig` to the global `twig.form_themes`; they now apply only to the Workflow UI forms. If your own forms relied on that side effect, add the theme to `config/packages/twig.yaml` (`twig: { form_themes: ['bootstrap_5_layout.html.twig'] }`).
- **Resolution:** definitions now also match Doctrine proxies, subclasses and implemented interfaces of their `subject_class`; when several classes match, the closest one wins before specificity/priority. Check for new `WorkflowAmbiguousMatchException`s if you had parent/child definitions with equal scores.
- **WorkflowContext:** non-string parameter values are cast to strings (booleans → `"true"`/`"false"`, `null` dropped); arrays and non-`Stringable` objects now throw `InvalidArgumentException`.
- **Locale switch:** `/locale/{_locale}` accepts the locales configured in `ui.locales` and answers 404 for any other locale.
- **Deprecated:** remove `nowo_workflow.environments` from your configuration (it never had an effect); register the bundle per environment in `config/bundles.php` instead.

## From 1.6.12 to 1.7.0

From **1.6.12** — security: UI fails closed when disabled, locale subscriber scoped to bundle routes, no open redirect.

```bash
composer update nowo-tech/workflow-bundle
```

- **Behaviour change:** with `enabled: false` the workflow UI routes now return 404 instead of being served without access checks; the default UI access checker is deny-all when security is not wired.
- The host request locale is no longer overridden (and no session is opened) on non-bundle routes.
- A `Referer` pointing to another host now redirects to the workflow dashboard after a locale switch.

## From 1.6.11 to 1.6.12

From **1.6.11** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/workflow-bundle
```

- No breaking changes. No application upgrade steps.

## From 1.6.10 to 1.6.11

From **1.6.10** — dependency refresh only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/workflow-bundle
```

## From 1.6.9 to 1.6.10

From **1.6.9** — UI `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## From 1.6.7 to 1.6.8

No breaking changes. **No application upgrade steps.** Behaviour change for long-running processes: `DatabaseWorkflowRegistry::get()` memoizes built workflows only for the current main request. Outside of an HTTP request (console commands, Messenger workers) or when the registry is instantiated manually without a `RequestStack`, each `get()` / `has()` call reloads the definition from the database. Calling `invalidate()` after editing definitions is still supported but no longer required for other workers to see the change.

```bash
composer update nowo-tech/workflow-bundle
```

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) for worker-mode guidance (scenario B: kernel not reset).

## From 1.6.6 to 1.6.7

No breaking changes. **No application upgrade steps.** Hosts using the default demo layout pick up extracted CDN partials automatically. Optional: `ui.layout_template: '@NowoWorkflowBundle/layout_integrate_base.html.twig'` when the project layout is `base.html.twig`.

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
| 1.x | 7.4+ (PHP 8.2+), 8.x (PHP 8.4+) | 8.2 – 8.5 |
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `workflow` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

## Unreleased

## To 1.6.10

From **1.6.9** — UI `access_roles` fail-closed; Doctrine `SortDirection`.

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.6.9

From **1.6.8** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/workflow-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

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

