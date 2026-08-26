# Configuration

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    enabled: true
    connection: default
    table_prefix: workflow_
    ui:
        path: '/workflow'
        layout_template: '@NowoWorkflowBundle/layout.html.twig'  # set to your project layout in real apps
        css_framework: bootstrap5   # bootstrap5 | bootstrap4 | tailwind | foundation | custom | none
        icon_set: bootstrap-icons   # bootstrap-icons | tabler-icons | ux_icon | svg_inline | none
        list_page_size: 20          # 0 = load all (not recommended in production)
        default_locale: en
        locales: [en, es, fr, it]
        required_roles: [ROLE_ADMIN]  # BC alias of security.access_roles
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\WorkflowUiAccessChecker
        allow_unauthenticated: false  # true only for local demos/tests without SecurityBundle
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable bundle services |
| `connection` | `default` | Doctrine connection for entities |
| `table_prefix` | `workflow_` | Prefix for Doctrine table and constraint names (`workflow_definition` → `{prefix}definition`) |
| `ui.path` | `/workflow` | Base path for CRUD UI (use as Symfony `access_control` path prefix) |
| `ui.layout_template` | `@NowoWorkflowBundle/layout.html.twig` | Twig layout extended by UI pages (Twig global `nowo_workflow_layout_template`). **Host apps should set this to the project layout** (or a one-file bridge). The default layout is for demos / standalone installs only. |
| `ui.css_framework` | `bootstrap5` | CSS stack for markup macros: `bootstrap`/`bootstrap5`, `bootstrap4`, `tailwind`, `foundation`, `custom`, `tabler` (alias of bootstrap5), `none`. With `custom`, templates rely on semantic `nowo-ui-*` classes. |
| `ui.icon_set` | `bootstrap-icons` | Icon rendering for row actions: `bootstrap-icons`, `tabler-icons`, `ux_icon`, `svg_inline`, `none` (text labels only) |
| `ui.list_page_size` | `20` | Definitions per page on dashboard and index (REQ-PERF-001). `0` = load all (not recommended in production). Query param `?page=` |
| `ui.default_locale` | `en` | Default locale for the CRUD UI |
| `ui.locales` | `en`, `es`, `fr`, `it` | Enabled locales for the locale switcher |
| `ui.required_roles` | `ROLE_ADMIN` | **BC alias** of `security.access_roles` |
| `security.access_roles` | `ROLE_ADMIN` | User must have **at least one** role. Empty list = no bundle-level role check (firewall / custom checker only) |
| `security.access_checker` | `null` | Optional service id implementing `WorkflowUiAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO only.** When `false` (default), SecurityBundle (`security.authorization_checker`) is required. Never `true` in production. |

Shipped translation catalogs: `en`, `es`, `fr`, `it`, `de`, `nl`, `pt`. Add `de`, `nl`, or `pt` to `ui.locales` to expose them in the switcher.

## Host layout integration (REQ-UI-001)

Preferred path — **do not** copy every CRUD Twig page:

1. Set `ui.layout_template` to your project layout (e.g. `base.html.twig`).
2. Set `ui.css_framework` to match the project (`bootstrap5`, `tailwind`, `foundation`, or `custom`).
3. Ensure the project layout defines `stylesheets` / `javascripts` (or use a bridge) so bundle pages can stack with `{{ parent() }}`.
4. Bundle pages extend `@NowoWorkflowBundle/base.html.twig`, which fills `nowo_ui_content` / `nowo_ui_styles` / `nowo_ui_scripts`.

### CSS framework examples

```yaml
# Bootstrap 5 (default demo)
nowo_workflow:
    ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
        icon_set: bootstrap-icons
```

```yaml
# Tailwind
nowo_workflow:
    ui:
        layout_template: 'base.html.twig'
        css_framework: tailwind
        icon_set: none
```

```yaml
# Foundation
nowo_workflow:
    ui:
        layout_template: 'base.html.twig'
        css_framework: foundation
```

```yaml
# Own design system — style .nowo-ui-* in project CSS
nowo_workflow:
    ui:
        layout_template: 'base.html.twig'
        css_framework: custom
        icon_set: svg_inline
```

### Bridge when content block names differ

```yaml
layout_template: 'admin/nowo_workflow_bridge.html.twig'
```

```twig
{# templates/admin/nowo_workflow_bridge.html.twig #}
{% extends 'base.html.twig' %}

{% block body %}
    {% block nowo_ui_content %}{% endblock %}
{% endblock %}
```

If the host layout is already `base.html.twig` with a `body` block, you can skip a project file and set:

```yaml
layout_template: '@NowoWorkflowBundle/layout_integrate_base.html.twig'
```

### Overridable Twig paths

| Path under `templates/bundles/NowoWorkflowBundle/` | Role |
| -------------------------------------------------- | ---- |
| `layout.html.twig` | Demo full-page layout (CDN via `_framework_assets.*.twig`) |
| `layout_integrate_base.html.twig` | Optional Nowo-to-Nowo bridge: `ui.layout_template: '@NowoWorkflowBundle/layout_integrate_base.html.twig'` when the host layout is `base.html.twig` and the content block is `body` |
| `base.html.twig` | Page shell (`parent()` asset stacking) |
| `dashboard/index.html.twig` | Dashboard (composes `@NowoUiKitBundle/macros/ui.html.twig`) |
| `workflow_definition/index.html.twig` | Definition list |
| `workflow_definition/form.html.twig` | Create/edit form |
| `workflow_definition/show.html.twig` | Definition detail |

Semantic CSS hooks: `nowo-ui-page-header`, `nowo-ui-toolbar`, `nowo-ui-table`, `nowo-ui-row-actions`, `nowo-ui-btn`, `nowo-ui-action--*`, `nowo-ui-modal`, `nowo-ui-flash`, `nowo-ui-empty`.

## Protecting the CRUD UI (REQ-UI-002)

Access is **deny-by-default**: with SecurityBundle present, `RoleBasedWorkflowUiAccessChecker` uses `security.access_roles`. Without SecurityBundle, container compilation fails unless `security.allow_unauthenticated: true` (demos/tests only) or a custom `security.access_checker` is set.

Also protect the path prefix with Symfony firewall `access_control`:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/workflow, roles: ROLE_ADMIN }
```

See [SECURITY.md](SECURITY.md#protecting-the-crud-ui).
