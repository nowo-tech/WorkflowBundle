- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `workflow`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

# Installation

**Requirements:** PHP 8.2+, Symfony 7.4+ or 8.x (Symfony 8 requires PHP 8.4+ and `doctrine/doctrine-bundle` ^3.2.4). See [UPGRADING.md](UPGRADING.md#compatibility).

```bash
composer require nowo-tech/workflow-bundle
```

## Table of contents

- [Bundle registration](#bundle-registration)
- [Routes](#routes)
- [Layout and assets (UiKit page contract)](#layout-and-assets-uikit-page-contract)
- [Database](#database)
- [Demo data](#demo-data)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)

## Bundle registration

```php
Nowo\WorkflowBundle\NowoWorkflowBundle::class => ['all' => true],
```

## Routes

```yaml
nowo_workflow:
    resource: '@NowoWorkflowBundle/Resources/config/routes.yaml'
```

## Layout and assets (UiKit page contract)

The admin pages extend UiKit 2.1 scaffolds: point `nowo_ui_kit.layouts.admin` at your layout or a one-file bridge
(see [CONFIGURATION.md](CONFIGURATION.md#host-layout-integration-req-ui-003)) and publish the bundle assets:

```bash
php bin/console assets:install
```

Run it once after every install / upgrade: the files land in `public/bundles/nowoworkflow/`. The bundle registers the
asset package **`nowo_workflow`** (`base_path: /bundles/nowoworkflow`) itself, and its templates load every file through
it (REQ-ASSETS-004):

```twig
<link rel="stylesheet" href="{{ asset('css/nowo-workflow.css', 'nowo_workflow') }}">
<script src="{{ asset('js/nowo-workflow.js', 'nowo_workflow') }}" defer></script>
<link rel="stylesheet" href="{{ asset('vendor/tom-select/tom-select.default.min.css', 'nowo_workflow') }}">
<script src="{{ asset('vendor/tom-select/tom-select.complete.min.js', 'nowo_workflow') }}" defer></script>
```

Use the same form in template overrides; do not hard-code `/bundles/nowoworkflow/…` and do not declare the
`nowo_workflow` package in `config/packages/framework.yaml` (only to point it at a CDN / other base URL).

The Workflow UI requires CSRF protection (`framework.csrf_protection` and `framework.form.csrf_protection`, on in a
default Symfony app): without it the container does not compile unless `nowo_workflow.web_ui.enabled: false`.

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
