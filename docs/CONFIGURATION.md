# Configuration

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    connection: default
    table_prefix: workflow_
    web_ui:
        enabled: true
        path_prefix: '/workflow'
        layout_template: null       # null = UiKit layout (nowo_ui_kit.layouts.admin)
        templates: {}               # per-page overrides: dashboard, index, show, new, edit
        list_page_size: 20          # 0 = load all (not recommended in production)
        default_locale: en
        locales: [en, es, fr, it]
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\WorkflowUiAccessChecker
        allow_unauthenticated: false  # true only for local demos/tests without SecurityBundle
```

| Option | Default | Description |
|--------|---------|-------------|
| `connection` | `default` | **Entity manager name** used by `nowo:workflow:sync-schema`. The bundle mapping (`NowoWorkflowBundle`) is always prepended to the **default** entity manager; to store workflow tables elsewhere, map `NowoWorkflowBundle` on that entity manager yourself and set this option to its name. |
| `table_prefix` | `workflow_` | Prefix for Doctrine table and constraint names (`workflow_definition` → `{prefix}definition`) |
| `web_ui.enabled` | `true` | When `false`, every Workflow UI route (`nowo_workflow_*`) answers 404; UI access checks stay wired (fail-closed). The registry keeps working. |
| `web_ui.path_prefix` | `/workflow` | Base path of the UI routes (use it as Symfony `access_control` path prefix) |
| `web_ui.layout_template` | `null` | Layout the pages extend. `null` = UiKit's `nowo_ui_kit.layouts.admin` (recommended: configure the layout once in UiKit). A custom value must render the UiKit canonical blocks (REQ-UI-003). |
| `web_ui.templates.<page>` | bundle templates | Per-page template (`dashboard`, `index`, `show`, `new`, `edit`); see [TEMPLATES.md](TEMPLATES.md#per-page-overrides) |
| `web_ui.list_page_size` | `20` | Definitions per page on the list (and rows of the dashboard card) (REQ-PERF-001). `0` = load all (not recommended in production). Query param `?page=` |
| `web_ui.default_locale` | `en` | Default locale for the UI (applied only to `nowo_workflow_*` routes; host routes keep their locale) |
| `web_ui.locales` | `en`, `es`, `fr`, `it` | Enabled UI locales (`/locale/{_locale}` accepts exactly these values, any other answers 404; Twig global `nowo_workflow_locales`). |
| `security.access_roles` | `ROLE_ADMIN` | User must have **at least one** role. An empty list is **rejected** unless `security.access_checker` is set (your checker decides) or `security.allow_unauthenticated: true` (dev/demo only). |
| `security.access_checker` | `null` | Optional service id implementing `WorkflowUiAccessCheckerInterface` |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO only.** When `false` (default), SecurityBundle (`security.authorization_checker`) is required. Never `true` in production. |

Shipped translation catalogs: `en`, `es`, `fr`, `it`, `de`, `nl`, `pt`. Add `de`, `nl`, or `pt` to `web_ui.locales` to expose them.

The CSS stack, icon set and row-action display come from UiKit (`nowo_ui_kit.css_framework`, `icon_set`,
`row_actions_display`); the form theme from the host (`twig.form_themes`) and the FormKit `workflow` profile
(seeded with the classes of the UiKit stack when the host does not define it).

Every form type of the bundle (`WorkflowDefinitionFormType`, `WorkflowPlaceType`, `WorkflowTransitionType`,
`WorkflowMatchRuleType` and the embedded `PlaceMultiSelectType`) is a FormKit Type on the profile
`nowo_form_kit.profiles.workflow` (REQ-TWIG-005 rule 11). To change field classes or conventions, define that profile
in the host (the bundle then seeds nothing for it):

```yaml
# config/packages/nowo_form_kit.yaml
nowo_form_kit:
    profiles:
        workflow:
            alias: workflow
            translation_domain: NowoWorkflowBundle
            defaults:
                attr: { class: 'nowo-ui-input nowo-ui-input--full form-control' }
                row_attr: { class: 'mb-3' }
```

The place multiselects keep their own classes (`nowo-ui-input nowo-ui-input--full nowo-place-multiselect`; the
transitions page script enhances `.nowo-place-multiselect`).

### Deprecated keys (since 1.9, removed in 2.0)

Still read and mapped (a canonical key set explicitly wins); each one reports a deprecation:

| Deprecated | Use instead |
| --- | --- |
| `enabled` | `web_ui.enabled` |
| `ui.path` | `web_ui.path_prefix` |
| `ui.layout_template` | `web_ui.layout_template` with a layout rendering the canonical blocks, or `nowo_ui_kit.layouts.admin`. While set, pages render through the deprecated `@NowoWorkflowBundle/base.html.twig` bridge into that 1.x layout; the removed default `@NowoWorkflowBundle/layout.html.twig` maps to the UiKit layout. |
| `ui.css_framework` | `nowo_ui_kit.css_framework` (still seeded when the host does not set it; also re-enables the 1.x scoped Bootstrap form theme) |
| `ui.icon_set` | `nowo_ui_kit.icon_set` (still seeded when the host does not set it) |
| `ui.list_page_size`, `ui.default_locale`, `ui.locales` | `web_ui.list_page_size`, `web_ui.default_locale`, `web_ui.locales` |
| `ui.required_roles` | `security.access_roles` |
| `environments` (since 1.8) | register the bundle per environment in `config/bundles.php` |

Deprecated Twig globals: `nowo_workflow_layout_template`, `nowo_workflow_css_framework`, `nowo_workflow_icon_set`,
`nowo_workflow_form_themes`.

## Host layout integration (REQ-UI-003)

Preferred path — configure UiKit once, **do not** copy Workflow pages:

1. Point `nowo_ui_kit.layouts.admin` at your layout or a one-file bridge that renders the UiKit canonical blocks
   (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_page_header`, `nowo_ui_flashes`,
   `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`) and loads UiKit's `nowo-ui.css` / `nowo-ui-confirm.js`.
2. Theme `nowo_ui_kit.theme` (tokens + class map) once for every bundle.
3. Run `php bin/console assets:install` (the pages load `bundles/nowoworkflow/…` through the `nowo_workflow` asset package, see [INSTALLATION.md](INSTALLATION.md#layout-and-assets-uikit-page-contract)).

```yaml
# config/packages/nowo_ui_kit.yaml
nowo_ui_kit:
    css_framework: bootstrap5
    layouts:
        admin: 'layouts/admin.html.twig'
```

```twig
{# templates/layouts/admin.html.twig — bridge into the project base #}
{% extends 'base.html.twig' %}
{% block title %}{% block nowo_ui_document_title %}{% endblock %}{% endblock %}
{% block stylesheets %}
    <link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">
    {{ nowo_ui_tokens() }}
    {{ parent() }}
    {% block nowo_ui_head %}{% endblock %}{% block nowo_ui_styles %}{% endblock %}
{% endblock %}
{% block body %}
    <main id="nowo-ui-content"{% if nowo_ui_bundle|default(null) %} data-nowo-ui-bundle="{{ nowo_ui_bundle }}"{% endif %}>
        {% block nowo_ui_page_header %}{% endblock %}
        {% block nowo_ui_flashes %}{{ include(nowo_ui_partial('flashes', nowo_ui_bundle|default(null)), {bundle: nowo_ui_bundle|default(null)}) }}{% endblock %}
        {% block nowo_ui_content %}{% endblock %}
    </main>
    {% block nowo_ui_modals %}{% endblock %}
{% endblock %}
{% block javascripts %}
    {{ parent() }}
    <script src="{{ asset('js/nowo-ui-confirm.js', 'nowo_ui_kit') }}" defer></script>
    {% block nowo_ui_scripts %}{% endblock %}
{% endblock %}
```

The demo (`demo/symfony8/templates/layouts/admin.html.twig`, `config/packages/nowo_ui_kit.yaml`) is a complete
example. Pages, data blocks, view models and assets: [TEMPLATES.md](TEMPLATES.md).

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
