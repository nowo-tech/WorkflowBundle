# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.9.2] - 2026-10-18](#192---2026-10-18)
- [[1.9.1] - 2026-10-18](#191---2026-10-18)
- [[1.9.0] - 2026-10-18](#190---2026-10-18)
- [[1.8.2] - 2026-10-13](#182---2026-10-13)
- [[1.8.1] - 2026-10-13](#181---2026-10-13)
- [[1.8.0] - 2026-10-13](#180---2026-10-13)
- [[1.7.0] - 2026-10-13](#170---2026-10-13)
- [[1.6.12] - 2026-10-13](#1612---2026-10-13)
- [[1.6.11] - 2026-10-09](#1611---2026-10-09)
- [[1.6.10] - 2026-09-28](#1610---2026-09-28)
- [[1.6.9] - 2026-09-27](#169---2026-09-27)
- [[1.6.8] - 2026-09-25](#168---2026-09-25)
- [[1.6.7] - 2026-08-26](#167---2026-08-26)
- [[1.6.6] - 2026-08-24](#166---2026-08-24)
- [[1.6.5] - 2026-08-19](#165---2026-08-19)
- [[1.6.4] - 2026-08-19](#164---2026-08-19)
- [[1.6.3] - 2026-08-19](#163---2026-08-19)
- [[1.6.2] - 2026-08-18](#162---2026-08-18)
- [[1.6.1] - 2026-08-07](#161---2026-08-07)
- [[1.6.0] - 2026-08-04](#160---2026-08-04)
- [[1.5.2] - 2026-07-29](#152---2026-07-29)
- [[1.5.1] - 2026-07-27](#151---2026-07-27)
- [[1.5.0] - 2026-07-27](#150---2026-07-27)
- [[1.4.3] - 2026-07-22](#143---2026-07-22)
- [[1.4.2] - 2026-07-16](#142---2026-07-16)
- [[1.4.1] - 2026-07-15](#141---2026-07-15)
- [[1.4.0] - 2026-07-08](#140---2026-07-08)
- [[1.3.1] - 2026-07-07](#131---2026-07-07)
- [[1.3.0] - 2026-07-07](#130---2026-07-07)
- [[1.2.0] - 2026-06-30](#120---2026-06-30)
- [[1.1.0] - 2026-06-30](#110---2026-06-30)
- [[1.0.0] - 2026-06-10](#100---2026-06-10)

## [Unreleased]

### Changed

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `coverage-check`, `validate-translations`, demo release-check); `check-open-prs` also fails on conflicted pull requests (REQ-REL-003). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- Documentation: wave-1 REQ compliance — README GitHub stars badge uses the package name, documentation list order (Templates/PSR under Additional documentation), canonical issue templates, CONTRIBUTING Code of Conduct link and Cursor Agent queue settings (REQ-IDE-006), tables of contents on long docs, Spec Kit inventory refresh after the UiKit page migration (79/79).

## [1.9.2] - 2026-10-18

Requires UiKit ^2.2; workarounds made unnecessary by 2.2 removed. See [UPGRADING](UPGRADING.md#to-192).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, numeric
  headers aligned with their cells, subtle badges, help text spacing). See [UPGRADING](UPGRADING.md#unreleased).
- Flow diagram: the transition links of a place dialog swap dialogs through the UiKit 2.2 opener
  (`data-nowo-ui-dialog-replace`, focus handed back to the diagram) instead of the bundle's own capture-phase
  handler (`data-workflow-dialog-swap` and its script removed).
- Transitions editor: Tom Select chips and focus ring painted from the UiKit primary token (UiKit
  `css/nowo-ui-tom-select.css`); the collection remove confirmation uses the solid destructive button
  (`btn_danger_solid`).
- `layout_integrate_base.html.twig` and the deprecated 1.x bridge `base.html.twig` print the UiKit runtimes through
  the UiKit `scripts` partial (the 1.x bridge loaded only the confirm runtime). Demo host layout likewise.

[1.9.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.2

## [1.9.1] - 2026-10-18

Flow diagram arrows reach their real targets (ranked layout, SVG connectors, only true back-edges listed as loops); end-aligned numeric columns, neutral Default badge, code slot for identifiers. See [UPGRADING](UPGRADING.md#to-191).

### Fixed

- **Flow diagram of branching definitions.** Transition labels and arrows pointed at the wrong places (e.g. "Submit
  for review" landed on Cancelled), Cancelled was ranked next to Review, and forward edges (review → cancel →
  cancelled) were listed under "Return / loop transitions". The new `WorkflowGraphLayout` ranks places by the longest
  path from the initial place, classifies only true back-edges (target ranked at or before the source, found by a
  depth-first search) as loops, orders each rank by barycentre sweeps, and draws the diagram as an SVG: one curved
  connector per edge from its source to the middle of its own target place (long edges pass through waypoints), each
  label on its own connector (moved along it when two labels would collide). Places and labels stay focusable
  buttons that open the place / transition dialogs; hovering or focusing a label highlights its connector.
- Demo: e2e covers the diagram geometry (every connector ends at the middle of its own target, only Resubmit and
  Reopen listed as loops); README screenshots re-captured (REQ-DEMO-013).

### Changed

- **Definitions list.** The match rules column is end-aligned like priority, places and transitions (`Column`
  `align: 'end'`; the cells keep `nowo-ui-cell--end`); the "Default" badge uses the neutral badge slot
  (`badge_neutral`) instead of the solid info badge, and its label is capitalised in every locale.
- Inline identifiers (slug, subject class, place names in the diagram, dialogs and loop list, and the code cells the
  collection editors build in JS — `data-code-class` on the collection root) use the UiKit `code` slot.
- `WorkflowGraphPresenter::present()` layout: `columns` are the ranks of the new layout, `bridges[<rank>]` lists the
  forward edges leaving that rank (also edges spanning several ranks), `backEdges` only true loops; new keys `ranks`
  (place → rank) and `diagram` (SVG geometry: `width`, `height`, `nodes`, `edges` with `path` and label box; `null`
  for linear definitions, which keep `sequence`).

[1.9.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-10-18

Workflow UI on the UiKit 2.1 standard page contract (REQ-UI-003): dashboard, list, show, form and settings scaffolds, UiKit native dialogs, no CDN assets (Tom Select vendored); deprecated `ui.*` keys mapped to `web_ui.*` / `security.*`. See [UPGRADING](UPGRADING.md#to-190).

### Added

- **UiKit 2.0 page contract (REQ-UI-003).** The admin pages extend UiKit scaffolds and receive view models from the
  new `Nowo\WorkflowBundle\View\WorkflowPageFactory`: dashboard → `page/dashboard` (KPI tiles + definitions card),
  definitions → `page/list` (view / edit / delete row actions, delete behind the UiKit confirm dialog, `Pagination`,
  empty state, breadcrumbs), detail → `page/show` (detail sections, created / updated meta, flow diagram with
  place / transition dialogs), new → `page/form`, edit → `page/settings` (one tab per section). See
  [TEMPLATES.md](TEMPLATES.md).
- Canonical configuration keys: `web_ui.enabled`, `web_ui.path_prefix`, `web_ui.layout_template` (default `null` →
  `nowo_ui_kit.layouts.admin`), `web_ui.templates.<page>` (`dashboard`, `index`, `show`, `new`, `edit`),
  `web_ui.list_page_size`, `web_ui.default_locale`, `web_ui.locales`.
- Delete action on the definitions list rows and on the edit pages (same CSRF-protected `POST` route).
- Bundle assets in `src/Resources/public/` (`assets:install` → `bundles/nowoworkflow/`): `css/nowo-workflow.css`
  (diagram, dialog content and editors painted from `--nowo-ui-*` tokens), `js/nowo-workflow.js` (collection
  editors; the dialogs are UiKit's) and Tom Select 2.4.3 (`vendor/tom-select/`, Apache-2.0) for the transitions editor.
- Collection editors validate the entry inside the dialog (required fields) before applying it.
- Real translations (7 locales) for the new labels and for the FormKit placeholder / help convention keys of every
  form field.
- `PageContractTest` (UiKit `PageContractTestCase`) over every page; demo Playwright e2e (`make -C demo/symfony8
  test-e2e`) and WebP screenshots in `docs/images/demo/` (REQ-DEMO-013).

### Changed

- **Template contract:** every page template was rewritten on a UiKit scaffold (view model `page`, data blocks
  only); `workflow_definition/form.html.twig` is now the *new* page only and the edit sections render
  `workflow_definition/edit.html.twig`. Markup uses `nowo-ui-*` classes and UiKit theme slots, no framework
  utility classes, no inline `<script>` / `<style>` / `onsubmit` / `confirm()`.
- The dashboard shows KPI tiles and the first `web_ui.list_page_size` definitions (link to the full list) instead of a
  paginated table.
- Forms use the host form theme and the FormKit `workflow` profile; the profile is seeded with Bootstrap control
  classes only on Bootstrap stacks (`nowo_ui_kit.css_framework`), semantic classes otherwise. Text controls use the
  UiKit 2.1 `input_full` slot classes (`nowo-ui-input nowo-ui-input--full`), so the bundle CSS no longer overrides
  `.nowo-ui-input`.
- The deprecated `ui.css_framework` form themes are passed as `FormPage::$formThemes` /
  `SettingsSection::$formThemes` (UiKit 2.1) instead of `form_theme` tags in the page templates.
- Place / transition dialogs and the collection editor dialog render UiKit's `dialog` partial and open through
  `data-nowo-ui-dialog-open` (`js/nowo-ui-dialog.js`, which the show / edit pages also load for host layouts with
  their own script list); the editor dialog gains the UiKit header close button (discards the entry like Cancel).
- `PlaceMultiSelectType` no longer adds the Bootstrap `form-select` class.
- Flash messages are translated by the controller (UiKit's flashes partial prints them as given).
- Requires `nowo-tech/ui-kit-bundle` `^2.1` (collection prototypes in the contract test, `dialog` partial,
  `input_full` slot, page form themes).

### Deprecated

- `enabled` → `web_ui.enabled`; `ui.path` → `web_ui.path_prefix`; `ui.layout_template` → `web_ui.layout_template`
  / `nowo_ui_kit.layouts.admin` (still honoured through the deprecated `@NowoWorkflowBundle/base.html.twig` bridge);
  `ui.list_page_size`, `ui.default_locale`, `ui.locales` → `web_ui.*`; `ui.required_roles` →
  `security.access_roles`; `ui.css_framework` / `ui.icon_set` → `nowo_ui_kit.css_framework` / `icon_set`.
- Twig globals `nowo_workflow_layout_template`, `nowo_workflow_css_framework`, `nowo_workflow_icon_set`,
  `nowo_workflow_form_themes`; templates `base.html.twig`, `layout_integrate_base.html.twig`,
  `_locale_switcher.html.twig`. All removed in 2.0.

### Removed

- Default CDN layout `layout.html.twig` and the 4-framework CDN switch (`_framework_assets.css.twig` /
  `.js.twig`), local `_pagination.html.twig`, `workflow_definition/_edit_nav.html.twig`, the Bootstrap-modal
  collection partials (`_match_rules_section`, `_places_section`, `_transitions_section`,
  `_collection_manager.js.twig`) and the Tom Select CDN.
- `workflow_definition/_dialog.html.twig` (the bundle `<dialog>` shell on the `confirm_*` slots) and the
  `data-workflow-dialog-open` / `data-workflow-dialog-close` attributes: use UiKit's `dialog` partial and
  `data-nowo-ui-dialog-open` / `data-nowo-ui-dialog-close`.

### Fixed

- The definitions list showed fewer rows than `list_page_size` (the page limit applied to fetch-joined rows); it now
  paginates definitions (Doctrine `Paginator`).

[1.9.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.9.0

## [1.8.2] - 2026-10-13

### Changed

- **Twig coding standard**: `make twig-lint` (twig-cs-fixer) now passes with 0 errors (19 coding-standard findings, no template bugs). Admin templates use the `include()` function instead of the `{% include %}` tag, multi-line hashes/arrays get trailing commas and the form `id` attribute key is unquoted. Rendered markup is unchanged apart from insignificant whitespace (one newline after each include). CI runs `composer twig:lint` in a new **Twig lint** job.

[1.8.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.2

## [1.8.1] - 2026-10-13

### Fixed

- Default UI layout (`@NowoWorkflowBundle/layout.html.twig`) no longer renders `nowo_ui_content` / `nowo_ui_modals` a second time after the `body` block. Every Workflow UI page was output twice, and the definition form pages (`/workflow/definitions/new`, `/workflow/definitions/{id}/edit/*`) failed with HTTP 500 `Field "name" has already been rendered`. Hosts with their own `ui.layout_template` were not affected.

[1.8.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-10-13

### Fixed

- Workflow resolution now matches Doctrine proxies and subclasses of a definition's `subject_class` (real class + parents + interfaces, closest class first), consistent with `WorkflowApplicator`'s `is_a()` check; previously only the exact `::class` matched.
- `WorkflowContext` normalizes parameter values to strings (ints/floats cast, booleans `"true"`/`"false"`, `Stringable` cast, `null` dropped) so `get()` no longer throws `TypeError` for non-string values; arrays/objects are rejected with `InvalidArgumentException`.
- Locale switch route accepts every locale configured in `ui.locales` (e.g. `de`, `nl`, `pt`) instead of a hard-coded `en|es|fr|it`; locales outside `ui.locales` answer 404.
- Docs: `security.access_roles` cannot be empty unless `access_checker` or `allow_unauthenticated` is set; `connection` is the entity manager used by `nowo:workflow:sync-schema` (the mapping is always registered on the default entity manager).
- CI/tests: `LocaleControllerTest` reads the `#[Route]` attribute's public `requirements` property (available since Symfony 7.4) instead of `getRequirements()`, which no longer exists in Symfony 8.x.

### Changed

- Bootstrap form themes are applied only to the bundle's own forms (`{% form_theme %}` + Twig global `nowo_workflow_form_themes`) instead of being prepended to the global `twig.form_themes`, which restyled every host form.
- Symfony floor raised to `^7.4 || ^8.0` (the CI `7.0` cell actually resolved 7.4); `symfony/yaml` is now a runtime requirement (the extension uses `YamlFileLoader`).

### Deprecated

- `nowo_workflow.environments` has no effect and triggers a deprecation when set; it will be removed in 2.0.

[1.8.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-13

### Security

- `enabled: false` no longer disables UI access control (fail-open): Workflow UI routes now answer 404 and the access checker is always wired (fail-closed `DenyAllWorkflowUiAccessChecker` fallback; also the default alias).
- Locale switch (`/locale/{_locale}`) no longer redirects to arbitrary `Referer` URLs (open redirect); only same-host or local paths are followed, otherwise it redirects to the dashboard.

### Fixed

- `LocaleSubscriber` only applies `ui.default_locale` / the stored UI locale to `nowo_workflow_*` routes; host application requests keep their locale and no longer open the session.

[1.7.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.7.0

## [1.6.12] - 2026-10-13

### Dependencies

- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #49).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #50).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #51).

[1.6.12]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.12

## [1.6.11] - 2026-10-09

### Dependencies

- Dependabot: `doctrine/orm` 3.7.3, `nowo-tech/form-kit-bundle` 2.5.4, `nowo-tech/ui-kit-bundle` 1.8.5, `igor-php/igor-php` `^0.10.0` (dev), phpstan group.
- Composer refresh: `symfony/workflow` 7.4.20, `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.2, `doctrine/orm` 3.7.4 (lockfile); dev tooling `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.6.11]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.11

## [1.6.10] - 2026-09-28

### Security

- Empty `security.access_roles` is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set. `RoleBasedWorkflowUiAccessChecker` denies access when given no roles.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.6.9] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.6.10]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.10
[1.6.9]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.9

## [1.6.8] - 2026-09-25

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `DatabaseWorkflowRegistry` now memoizes built workflows only for the current main request (and implements `ResetInterface`), so definitions edited or disabled in one worker are seen by every worker on the next request. Outside of an HTTP request (CLI, Messenger) nothing is memoized.
- `DatabaseMetadataStore` copies labels and workflow metadata at construction instead of keeping the `WorkflowDefinition` entity.
- `WorkflowApplicator` and `WorkflowDefinitionController` reset a closed EntityManager (through `ManagerRegistry`) after a failed `flush()` before rethrowing, so a worker does not keep a closed manager for later requests.
- PHPStan level 8 clean with `src/Form` and `src/Entity` included (Form `@extends` generics; entity `$id` visibility for ORM-assigned ids).
- Demo FrankenPHP stack restored to **PostgreSQL** (`pdo_pgsql`) to match the Dockerfile and `.env.example`; `release-verify` now fails if demo `up` fails.

### Documentation

- [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md): remediation for W-01–W-03; baseline spec **FR-WF-007** / **FR-DB-003**.
- [UPGRADING.md](UPGRADING.md), [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md), [USAGE.md](USAGE.md).

### Notes

- New optional constructor arguments (`?RequestStack` on `DatabaseWorkflowRegistry`, `?ManagerRegistry` on `WorkflowApplicator` and `WorkflowDefinitionController`) are autowired; no configuration changes.

[1.6.8]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.8

## [1.6.7] - 2026-08-26

### Added

- **REQ-UI-001:** `CssFramework` / `IconSet` PHP enums for config validation; CDN assets extracted to `_framework_assets.css.twig` / `_framework_assets.js.twig`; optional layout bridge `@NowoWorkflowBundle/layout_integrate_base.html.twig`.

### Changed

- Demo layout uses semantic `nowo-ui-*` hooks and loads framework CSS/JS via the extracted partials (Bootstrap 4 keeps its own CDN).
- **Docs:** CONTRIBUTING notes phpstan-frankenphp as maintainer-only (REQ-CS-005).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.6.7]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.7

## [1.6.6] - 2026-08-24

### Changed

- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.6.6]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.6

## [1.6.5] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_workflow.yaml` access rules (REQ-SEC-004). Prefer **`^1.6.5`** over `v1.6.4`.

## [1.6.4] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for workflow routes (REQ-SEC-004).

## [1.6.3] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.6.3]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.3

## [1.6.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.6.2]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.2

## [1.6.1] - 2026-08-07

### Fixed

- **CI / Composer:** regenerate `composer.lock` content-hash after the v1.6.0 `composer.json` changes (FormKit, UiKit, Twig Extra, Twig-CS-Fixer). `composer validate --strict` on the default branch is green again (REQ-CI-003).

### Tests

- Cover `prependFormKitDefaults` / `prependUiKitDefaults` (seed, host override, non-array UiKit config bags) so line coverage stays at 100%.

[1.6.1]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `workflow` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Import `@NowoUiKitBundle/macros/ui.html.twig`; removed local `_ui_macros.html.twig`. Requires `nowo-tech/ui-kit-bundle` `^1.4`. Extension seeds `nowo_ui_kit` from `ui.css_framework` / `icon_set` when the host has not configured UiKit.

[1.6.0]: https://github.com/nowo-tech/WorkflowBundle/releases/tag/v1.6.0

## [1.5.2] - 2026-07-29

### Added

- `make coverage-check` (100% clover gate) and `scripts/check-coverage.php` (REQ-TEST-006).
- `docs/SECURITY.md` 12.4.1 row for REQ-SEC-004 Pass (conditional).

### Changed

- `release-check` no longer runs `cs-fix`; uses `coverage-check`.
- PHPStan: `ignoreErrors: []` (REQ-CS-006); Form paths excluded for generics noise.
- Packagist homepage + keywords (`php`, `frankenphp`, `symfony-bundle`).
- Root `docker-compose.yml` fixed IPAM subnet (Docker address-pool exhaustion on dense hosts).
- `SchemaSyncService::listExistingSequenceNames` maps Doctrine `Sequence` objects only (DBAL 4).

## [1.5.1] - 2026-07-27

### Added

- REQ-MAKE-003: demo aggregate Makefile aliases `up` / `down` / `update-bundle`
- REQ-REL-003: `make check-open-prs` (included in `release-check`)
- REQ-DEMO-001: Symfony DebugBundle in the FrankenPHP demo (`require-dev`)
- Demo `release-verify` HTTP smoke (boot → HTTP 2xx/3xx → down)

### Documentation

- [RELEASE.md](RELEASE.md): open-PR gate and demo smoke in the pre-release pipeline
- [UPGRADING.md](UPGRADING.md): notes for 1.5.1

## [1.5.0] - 2026-07-27

### Breaking

- **Web UI private by default (REQ-UI-002):** the CRUD UI is no longer open by default. Without `symfony/security-bundle`, container compilation **fails** unless you set `nowo_workflow.security.allow_unauthenticated: true` (local demos/tests only) or provide `security.access_checker`. With SecurityBundle, `RoleBasedWorkflowUiAccessChecker` is auto-wired from `security.access_roles` (default `ROLE_ADMIN`).
- Empty `security.access_roles` / `ui.required_roles` now means **no** bundle-level role check (previously denied all access).

### Added

- `ui.layout_template`, `ui.css_framework`, `ui.icon_set` (REQ-UI-001) with Twig globals and multi-framework `_ui_macros.html.twig`
- `ui.list_page_size` (default `20`) pagination for dashboard and definition index (`?page=`); list queries eager-load associations (REQ-PERF-001)
- Root `security` config: `access_roles`, `access_checker`, `allow_unauthenticated`
- `WorkflowUiSecurityPass` compile-time SecurityBundle guard
- `@NowoWorkflowBundle/base.html.twig` page shell with `{{ parent() }}` asset stacking and stable `nowo_ui_*` blocks
- Semantic `nowo-ui-*` CSS hooks on admin markup
- FrankenPHP PHPStan rules (`nowo-tech/phpstan-frankenphp`) and README worker-mode banner (REQ-CS-005 / REQ-DOCS-017)
- `.scrutinizer.yml` (REQ-CI-002)
- `make demo-smoke`, `make down-dev`; demo `setup` / `verify` targets

### Changed

- Pages extend configurable layout via `base.html.twig` instead of hard-coding the demo layout alone
- Flex recipe documents security defaults; Symfony 8 demo sets `allow_unauthenticated: true`
- README: Documentation link order (REQ-DOCS-002); Symfony badge `7.4 | 8.0 | 8.1+`
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005)
- Spec Kit baseline + code inventory updated for UI/security/pagination

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md), [SECURITY.md](SECURITY.md), [USAGE.md](USAGE.md), [UPGRADING.md](UPGRADING.md), [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)

## [1.4.3] - 2026-07-22

### Changed

- Demo FrankenPHP entrypoint extracted to `demo/symfony8/docker/entrypoint.sh`; runtime mode selected via `FRANKENPHP_MODE` (`classic` \| `worker`, default `worker`)
- PHP-CS-Fixer: `fully_qualified_strict_types.import_symbols` enabled (FQCN → `use` imports)
- GitHub Actions: `actions/checkout` v6 → v7
- Lockfile synced (`doctrine/dbal`, `doctrine/doctrine-bundle`)

### Fixed

- REQ-GIT-001 verification uses `git --no-replace-objects` so local `git replace` refs cannot hide Cursor co-author trailers from CI
- History rewrite script refuses a dirty working tree before rewriting
- REQ-GITIGNORE-002: ignore `.php-cs-fixer.cache` as a file (stop tracking the cache)

### Documentation

- [GITHUB_CI.md](GITHUB_CI.md): expanded REQ-GIT-001 operator guide (scope, why CI enforces it, replace-refs pitfall)
- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): document `FRANKENPHP_MODE` and standalone entrypoint

## [1.4.2] - 2026-07-16

### Changed

- Renamed CI requirements doc `docs/GITLAB_CI.md` → `docs/GITHUB_CI.md` and aligned content with GitHub Actions (REQ-GIT-001)

### Documentation

- [GITHUB_CI.md](GITHUB_CI.md): GitHub Actions CI requirements for REQ-GIT-001
- [README.md](../README.md), [CONTRIBUTING.md](CONTRIBUTING.md): updated links to `GITHUB_CI.md`

## [1.4.1] - 2026-07-15

### Added

- Contributor Covenant Code of Conduct (`CODE_OF_CONDUCT.md`)
- REQ-GIT-001: verification script, history cleanup script, and `commit-msg` hook to block Cursor `Co-authored-by` trailers
- CI job `git-hygiene` enforcing REQ-GIT-001 on push and pull requests
- Makefile targets `check-no-cursor-coauthor` and `strip-cursor-coauthor-from-history`; `release-check` now includes co-author verification

### Changed

- GitHub Actions: `actions/cache` v5 → v6
- Dev lockfiles synced (`friendsofphp/php-cs-fixer`, `rector/rector`)

### Documentation

- CI requirements doc for REQ-GIT-001 (shipped as `GITLAB_CI.md`; renamed to `GITHUB_CI.md` in 1.4.2)
- [CONTRIBUTING.md](CONTRIBUTING.md): Code of Conduct reference and git hooks workflow
- [RELEASE.md](RELEASE.md): post-tag co-author check reminder before push
- [README.md](../README.md): links to Code of Conduct and CI requirements
- `.gitignore`: ignore `.cursor/sandbox.json` (machine-specific local file)

## [1.4.0] - 2026-07-08

### Added

- GitHub Spec Kit integration (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`)
- Baseline specification and code inventory under `specs/001-baseline/` (100% coverage of production code in `src/`)
- [SPEC-KIT.md](SPEC-KIT.md) — operator manual for Spec Kit install, initialization, and maintainer workflow

### Documentation

- [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md): three-layer SDD model (Spec Kit baseline, product behavior, `REQ-*` traceability) and contributor workflow
- [README.md](../README.md): link to Spec Kit documentation

## [1.3.1] - 2026-07-07

### Fixed

- Integration tests on PHP 8.4+: enable Doctrine native lazy objects in `IntegrationEntityManagerFactory`

## [1.3.0] - 2026-07-07

### Added

- `RoleBasedWorkflowUiAccessChecker` — built-in `WorkflowUiAccessCheckerInterface` using Symfony `AuthorizationCheckerInterface` (grant if the user has any configured role)
- Translation catalogs for **German** (`de`), **Dutch** (`nl`), and **Portuguese** (`pt`)
- Configuration key `ui.required_roles` (default `ROLE_ADMIN`) — documents expected roles for Flex/recipe wiring
- Flex recipe snippet `config/services/nowo_workflow_security.yaml` (commented) to alias the role-based checker when `symfony/security-bundle` is installed

### Changed

- French UI translations refined (labels for workflow type, transitions, and form fields)

### Fixed

- Demo `symfony8` Makefile: define `COMPOSE` and `SERVICE_PHP` so `make update-deps` resolves the correct Docker Compose service

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md): `ui.required_roles` and shipped locale catalogs
- [SECURITY.md](SECURITY.md): built-in `RoleBasedWorkflowUiAccessChecker` and Flex recipe wiring
- [UPGRADING.md](UPGRADING.md): notes for upgrading to 1.3.0

## [1.2.0] - 2026-06-30

### Changed

- **PHP** minimum raised to **8.2** (was 8.1)
- **Symfony** support narrowed to **7.x and 8.x** (Symfony 6.4 removed)
- **`doctrine/doctrine-bundle`** constraint updated to `^2.13 || ^3.2.4` (`3.2.4+` required for Symfony 8; needs PHP 8.4+)

### Fixed

- CI matrix: Symfony 8 jobs use `doctrine-bundle` ^3.2.4 and `--dev` for test-only Symfony packages
- CI excludes Symfony 8 on PHP 8.2/8.3 (Doctrine Bundle 3.2.4 requires PHP 8.4+)

### Documentation

- [README.md](../README.md), [UPGRADING.md](UPGRADING.md), [CONTRIBUTING.md](CONTRIBUTING.md): updated compatibility matrix

## [1.1.0] - 2026-06-30

### Added

- Configurable `table_prefix` for Doctrine table and constraint names (default `workflow_`)
- `WorkflowUiAccessCheckerInterface` and `AllowAllWorkflowUiAccessChecker` to protect the CRUD UI without Symfony `access_control` (open access when no custom checker is registered)
- `WorkflowRegistryInterface` as extension point for workflow resolution (`DatabaseWorkflowRegistry` implements it)
- `SchemaSyncService::executeStatements()` for idempotent schema SQL execution with duplicate-object tolerance
- Integration tests for schema sync and the workflow definition repository
- Unit test suite expanded to ~100% PHP coverage

### Changed

- `WorkflowApplicator` now type-hints `WorkflowRegistryInterface` instead of `DatabaseWorkflowRegistry`
- Translation catalog files renamed to `NowoWorkflowBundle.<locale>.yaml` (translation domain unchanged)

### Documentation

- [SECURITY.md](SECURITY.md): CRUD UI protection guide with `WorkflowUiAccessCheckerInterface`
- [CONFIGURATION.md](CONFIGURATION.md): `table_prefix` and UI locale options
- [UPGRADING.md](UPGRADING.md): notes for upgrading to 1.1.0

## [1.0.0] - 2026-06-10

Initial stable release.

### Added

- Database-driven Symfony Workflow definitions with CRUD UI
- `WorkflowResolver`, `WorkflowApplicator`, and `DatabaseWorkflowRegistry`
- Match rules with multi-parameter resolution
- Demo application (Symfony 8, FrankenPHP, PostgreSQL)
- Flex recipe under `.symfony/recipe/nowo-tech/workflow-bundle/`
- Twig override support (`@NowoWorkflowBundle/...`) and translation domain `NowoWorkflowBundle`

### Documentation

- Full Nowo bundle standards alignment (Docker, CI, security, spec-driven docs)
