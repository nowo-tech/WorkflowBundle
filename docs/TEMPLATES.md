# Templates

Workflow admin pages on the UiKit 2.1 page contract (REQ-UI-003). Every page extends a UiKit scaffold
(`@NowoUiKit/page/*`), receives a view model built by `Nowo\WorkflowBundle\View\WorkflowPageFactory` (labels are
`Label`s of the `NowoWorkflowBundle` domain) and fills only data blocks. The layout is the host's
`nowo_ui_kit.layouts.admin` (or `nowo_workflow.web_ui.layout_template`); the theme is `nowo_ui_kit.theme`.

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Partials of the pages](#partials-of-the-pages)
- [Assets](#assets)
- [Per-page overrides](#per-page-overrides)
- [Templates removed in 2.0](#templates-removed-in-20)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Dashboard | `dashboard/index.html.twig` | `page/dashboard` | `DashboardPage` (header + create action; KPI tiles definitions / enabled / disabled); extra context `definitions`, `total` | `nowo_ui_dashboard_cards` (definitions card: name, type, subject, status), `nowo_ui_styles` |
| Definitions list | `workflow_definition/index.html.twig` | `page/list` | `ListPage` (columns name, slug, matchRules, priority, places, transitions; row actions view, edit, delete with `Confirm`; `Pagination`; `EmptyState` with create action; breadcrumbs) | `nowo_ui_list_row`, `nowo_ui_styles` |
| Definition detail | `workflow_definition/show.html.twig` | `page/show` | `ShowPage` (header edit + delete with `Confirm`; sections general, runtime binding, match rules; meta created / updated); extra context `definition`, `graph` | `nowo_ui_show_sections` (flow diagram, then the sections), `nowo_ui_modals` (place / transition dialogs), `nowo_ui_styles`, `nowo_ui_scripts` |
| New definition | `workflow_definition/form.html.twig` | `page/form` | `FormPage` (general section form, submit "Create and continue", cancel to the list) | `nowo_ui_form_body` (general fields grid, then the REQ-TWIG-003 loop), `nowo_ui_styles` |
| Edit definition | `workflow_definition/edit.html.twig` | `page/settings` | `SettingsPage` (one `SettingsSection` tab per section: general, match_rules, places, transitions; the current one carries the form and its `formThemes`; header view + delete with `Confirm`); extra context `definition`, `section` | `nowo_ui_settings_section` (general fields grid or the collection editor + submit / cancel), `nowo_ui_styles`, `nowo_ui_scripts` |

Every page sets `attributes: {data-testid: workflow-…}` on the scaffold root (`workflow-dashboard`,
`workflow-definitions`, `workflow-definition-show`, `workflow-definition-new`, `workflow-definition-edit`).

## Unique pages

(none) — every page extends a scaffold.

## Partials of the pages

Bundle-specific content of the data blocks (no chrome, theme slots only):

| Partial | Used by | Role |
| --- | --- | --- |
| `workflow_definition/_flow_diagram.html.twig` | show | flow diagram card (`card`, `card_*`, `badge_neutral`, `btn_secondary`, `toolbar`, `muted`, `code` slots; `nowo-workflow-diagram__*` classes): linear definitions as a row (`graph.layout.sequence`), branching ones as an SVG from `graph.layout.diagram` (`WorkflowGraphLayout`: ranks from the initial place, one connector per edge ending at its target, place / transition links in `foreignObject`s, since 2.3 openers of the shared flow dialog), true loops listed under it (`graph.layout.backEdges`) |
| `workflow_definition/_flow_dialogs.html.twig` | show | since 2.3 the ONE empty flow dialog (`remote_form_dialog`, id `workflow-flow-detail`); every state / transition of the diagram is a link to its own page (`nowo_workflow_definition_place` / `_transition`) that the dialog loads on open (`X-Nowo-Dialog: 1`, the fragment) — no place or transition detail is in the show page |
| `workflow_definition/_flow_place.html.twig`, `_flow_transition.html.twig` | place / transition page and its dialog fragment (2.3) | the detail list of a place (its incoming / outgoing transitions link to their page and load into the same dialog) or of a transition, then `_flow_actions.html.twig` (Close in the dialog, back to the definition on the full page) |
| `workflow_definition/flow_detail.html.twig` (`web_ui.templates.flow_detail`) | place / transition page (2.3) | the full page (UiKit `page/show`) of one place / transition: no JavaScript, deep link |
| `workflow_definition/_general_fields.html.twig` | new, edit (general) | general fields in a responsive grid |
| `workflow_definition/_collection_section.html.twig` | edit (match rules, places, transitions) | collection editor: table (`table*` slots), row template, entry prototype (`<template data-collection-prototype>`), editor dialog through `nowo_ui_partial('dialog')` (`lg` for transitions; `data-title-add` / `data-title-edit` on the `<dialog>`), remove confirmation through `nowo_ui_partial('confirm')` |

## Assets

Shipped under `src/Resources/public/` (`php bin/console assets:install` → `bundles/nowoworkflow/`), loaded through the
`nowo_workflow` asset package (`asset('js/nowo-workflow.js', 'nowo_workflow')`), never from a CDN:

| Asset | Loaded by | Role |
| --- | --- | --- |
| `css/nowo-workflow.css` | every page (`nowo_ui_styles`) | diagram, dialog content, collection editors, form grid — painted from `--nowo-ui-*` tokens; no overrides of UiKit classes |
| `js/nowo-workflow.js` | show, edit (`nowo_ui_scripts`) | collection editors, transition-label edge highlight — no framework JS, no inline handlers, no `confirm()` |
| `vendor/tom-select/` (2.4.3, Apache-2.0, `LICENSE`) | edit → transitions tab | searchable place multiselect; optional (native `<select multiple>` without it) |

The delete confirmations use UiKit's `nowo-ui-confirm.js`, which the host layout loads (UiKit layouts do). The
dialogs use UiKit's `nowo-ui-dialog.js`: UiKit layouts load it, and the show / edit pages add it to
`nowo_ui_scripts` too (it installs once) for host layouts that print their own script list.

## Per-page overrides

`nowo_workflow.web_ui.templates.<page>` (`dashboard`, `index`, `show`, `new`, `edit`): a host template that extends
`@!NowoWorkflowBundle/…` and overrides a data block, e.g.

```yaml
nowo_workflow:
    web_ui:
        templates:
            index: 'admin/workflow/index.html.twig'
```

```twig
{# templates/admin/workflow/index.html.twig #}
{% extends '@!NowoWorkflowBundle/workflow_definition/index.html.twig' %}

{% block nowo_ui_list_after %}<p class="{{ nowo_ui_class('muted', page.bundle) }}">Synced nightly.</p>{% endblock %}
```

Theme the view types once in UiKit (`templates/bundles/NowoUiKitBundle/page/*.html.twig`, `nowo_ui_kit.theme`)
instead of overriding Workflow pages.

## Templates removed in 2.0

Deprecated since 1.9 and removed in 2.0 (with the Twig globals `nowo_workflow_layout_template`,
`nowo_workflow_css_framework`, `nowo_workflow_icon_set` and `nowo_workflow_form_themes`):

| Removed template | Replacement |
| --- | --- |
| `base.html.twig` (1.x layout bridge used while `ui.layout_template` was set) | a layout that renders the UiKit canonical blocks, set as `nowo_ui_kit.layouts.admin` or `nowo_workflow.web_ui.layout_template` |
| `layout_integrate_base.html.twig` (one-file bridge into the project `base.html.twig`) | copy it from the v1.22.1 tag into your application as your `nowo_ui_kit.layouts.admin` bridge |
| `_locale_switcher.html.twig` | UiKit's `partials/_locale_switcher.html.twig` with the `nowo_workflow_locales` global |

## Allowed exceptions

- (none) — `PageContractTest` runs with the default allow-list (frameworks `none`, `bootstrap5`, `tailwind`).
