# Configuration

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    connection: default
    table_prefix: workflow_
    runtime:
        flush_after_apply: false    # 1.14 recipe value; unset = true (1.13 behaviour) with a deprecation, false in 2.0
    doctrine:
        entity_manager: null        # source manager of the private entity manager (null = the one mapping the entities)
        register_mapping: true      # false = map the bundle entities yourself (DDD host, dedicated manager)
        mapping_type: attribute     # or xml (src/Resources/config/doctrine/*.orm.xml)
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
        # access_checker: App\Security\WorkflowUiAccessPolicy   # implements WorkflowUiAccessPolicyInterface
        allow_unauthenticated: false  # true only for local demos/tests without SecurityBundle
```

| Option | Default | Description |
|--------|---------|-------------|
| `connection` | `default` | **Entity manager name** used by `nowo:workflow:sync-schema`. The bundle mapping (`NowoWorkflowBundle`) is always prepended to the **default** entity manager; to store workflow tables elsewhere, map `NowoWorkflowBundle` on that entity manager yourself and set this option to its name. |
| `table_prefix` | `workflow_` | Prefix for Doctrine table and constraint names (`workflow_definition` → `{prefix}definition`) |
| `runtime.flush_after_apply` | `true` when unset (deprecated), `false` in the 1.14 recipe | `true`: `WorkflowApplicator::apply*()` flushes the application's default entity manager after each transition, which commits **every** pending change of its unit of work (1.13 behaviour). `false`: only the marking is changed; the caller persists the subject (repository, transaction boundary). Not setting the key keeps `true` and triggers a deprecation (since 1.14): the default becomes `false` in 2.0. See [Flushing after apply](#flushing-after-apply-new-installs-and-upgrades). |
| `doctrine.entity_manager` | `null` | Since 1.14. Source manager of the bundle's **private** entity manager (connection, mapping configuration, event manager, SQL filters). `null` = the first manager of `doctrine.orm.entity_managers` that maps the bundle entities (the 1.13 rule, resolved at build time; a deprecation asks for the explicit option when it is not the default manager). A configured name must exist and map the bundle entities: the build fails otherwise. The bundle never flushes, clears or resets it. |
| `doctrine.private_entity_manager` | `true` | Since 1.14. `false` restores the 1.13 behaviour (the admin UI, demo seeder and repository use the application's default manager; `WorkflowDefinitionRepository::save()` / `remove()` then flush that manager, including the application's pending changes). **Deprecated** as soon as it is set; removed in 2.0. |
| `doctrine.register_mapping` | `true` | Since 1.14. `false` = the bundle does not prepend its mapping; map `Nowo\WorkflowBundle\Entity` yourself (e.g. only on a dedicated manager, from the shipped XML). |
| `doctrine.mapping_type` | `attribute` | Since 1.14. `xml` = the bundle registers `src/Resources/config/doctrine/*.orm.xml` (same tables, columns, indexes and constraints as the attributes; a test asserts the parity). |
| `web_ui.enabled` | `true` | When `false`, every Workflow UI route (`nowo_workflow_*`) answers 404; UI access checks stay wired (fail-closed). The registry keeps working. |
| `web_ui.path_prefix` | `/workflow` | Base path of the UI routes (use it as Symfony `access_control` path prefix) |
| `web_ui.layout_template` | `null` | Layout the pages extend. `null` = UiKit's `nowo_ui_kit.layouts.admin` (recommended: configure the layout once in UiKit). A custom value must render the UiKit canonical blocks (REQ-UI-003). |
| `web_ui.templates.<page>` | bundle templates | Per-page template (`dashboard`, `index`, `show`, `new`, `edit`); see [TEMPLATES.md](TEMPLATES.md#per-page-overrides) |
| `web_ui.list_page_size` | `20` | Definitions per page on the list (and rows of the dashboard card) (REQ-PERF-001). `0` = load all (not recommended in production). Query param `?page=` |
| `web_ui.default_locale` | `en` | Default locale for the UI (applied only to `nowo_workflow_*` routes; host routes keep their locale) |
| `web_ui.locales` | `en`, `es`, `fr`, `it` | Enabled UI locales (`/locale/{_locale}` accepts exactly these values, any other answers 404; Twig global `nowo_workflow_locales`). |
| `security.access_roles` | `ROLE_ADMIN` | User must have **at least one** role. An empty list is **rejected** unless `security.access_checker` is set (your checker decides) or `security.allow_unauthenticated: true` (dev/demo only). |
| `security.access_checker` | `null` | Optional service id implementing `WorkflowUiAccessPolicyInterface` (1.14). A service implementing only the deprecated `WorkflowUiAccessCheckerInterface` keeps working (deprecation at build time); a service implementing neither fails the build. |
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

## Standard Symfony host

Nothing to add to the recipe: attribute mapping on the default entity manager, definitions written through the
bundle's private entity manager, the role-based UI policy from `security.access_roles`, and
`runtime.flush_after_apply: false` (flush your subject after `apply*()`; see [USAGE.md](USAGE.md#standard-symfony-host)).

## DDD / hexagonal host

Opt-in keys: `doctrine.register_mapping: false` (map the shipped XML yourself, e.g. on a dedicated manager),
`doctrine.entity_manager: <that manager>`, `connection: <that manager>` (schema sync), `runtime.flush_after_apply:
false`, and your own `WorkflowUiAccessPolicyInterface` service aliased to the port. Full example in
[USAGE.md](USAGE.md#ddd--hexagonal-host).

## Flushing after apply: new installs and upgrades

`runtime.flush_after_apply` is the one setting whose recommended value changes in 1.14 (REQ-ARCH-001 rule 6: a
bundle never flushes the application's entity manager). The mechanism keeps every existing install unchanged:

| Situation | Value used | What happens |
|-----------|------------|--------------|
| New install with Symfony Flex (recipe `1.14`) | `false` (written in `config/packages/nowo_workflow.yaml`) | `apply*()` only changes the marking; your code flushes or saves the subject. |
| Upgrade from ≤ 1.13 without the key | `true` (tree default, unchanged) | Same as 1.13: `apply*()` flushes the default entity manager. A deprecation is triggered when the container is built. |
| Any install that sets the key | the value set | No deprecation. |
| 2.0 | `false` | The key's default becomes `false`; `true` stays available until then. |

To silence the deprecation without changing behaviour, set `flush_after_apply: true`. To move to the 2.0 behaviour,
set `false` and persist the subject after `apply*()` (the same database state results; this is covered by
`PersistenceEquivalenceKernelTest`).

## Doctrine: the private entity manager (1.14)

The workflow definitions (`WorkflowDefinition`, places, transitions, match rules) are read and written through a
**private entity manager** owned by the bundle (REQ-ARCH-001 rule 6). It is created on the connection, mapping
configuration and event manager of the source manager (`doctrine.entity_manager`, or the manager that maps the
entities), so the tables, the table prefix and your Doctrine listeners are unchanged. Its unit of work holds only
definition rows:

- saving a definition (admin UI, `nowo:workflow:seed-demo`) never commits a change your application left pending;
- a failed definition save closes only the private manager, which is created again on the next use; the bundle never
  resets one of your managers;
- the SQL filters enabled on the source manager (tenant, soft-delete…) are mirrored on every call;
- in a FrankenPHP worker (kernel reset false, REQ-RUNTIME-002) it is dropped at every outermost unit-of-work
  boundary: main request start and terminate, Messenger message received / handled / failed, outermost console
  command start and end (a nested command or a command inside a message keeps the outer unit); and at `kernel.reset`;
- after a rollback on its connection (doctrine/dbal 4 middleware on that connection only) its identity map is
  replaced at the next use, unless it still has pending changes, which are kept until flushed or the next boundary.

The connection is shared: a transaction your code opens around a bundle call includes the bundle's writes.
Unflushed `save($definition, false)` / `remove($definition, false)` changes kept across a rollback of your transaction
are not supported (flush right away inside your own transactions). Changes still pending when a boundary drops the
private manager are logged as a warning (entity classes and count).
`WorkflowDefinitionRepository` returns entities managed by the private manager: change and persist them through the
bundle (admin UI) or with `$repository->save($definition)` / `remove()` (1.14), not through your own manager. Set
`doctrine.private_entity_manager: false` (deprecated) to keep the 1.13 behaviour for one minor while you migrate.

The application's subjects (the objects a workflow applies to) are never in the private manager: they stay in your
manager, and `runtime.flush_after_apply` decides who flushes them.

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
