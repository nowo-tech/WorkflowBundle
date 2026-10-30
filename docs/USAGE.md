# Usage

## Table of contents

- [Match rules and resolution](#match-rules-and-resolution)
- [Runtime API](#runtime-api)
- [Extending entities](#extending-entities)
- [Protecting the CRUD UI](#protecting-the-crud-ui)
- [Custom workflow registry](#custom-workflow-registry)
- [FrankenPHP / long-running workers](#frankenphp--long-running-workers)
- [Overriding Twig templates (REQ-TWIG-001)](#overriding-twig-templates-req-twig-001)
- [Overriding translations (REQ-I18N-001)](#overriding-translations-req-i18n-001)
- [Browser JavaScript](#browser-javascript)
- [Forms in Twig (REQ-TWIG-005)](#forms-in-twig-req-twig-005)

## Match rules and resolution

Each `WorkflowDefinition` can define zero or more `WorkflowMatchRule` rows (`parameter_key` + `parameter_value`).

- **0 rules** — default workflow for the subject class when nothing more specific matches.
- **1 rule** — e.g. `document_type=invoice`.
- **2+ rules** — all must match (AND), e.g. `tenant=acme` + `department=finance`.

Resolution order:

1. Filter enabled definitions by subject class: the subject's real class (Doctrine `__CG__` proxies are unwrapped), its parent classes and its interfaces all match, consistent with `WorkflowApplicator`'s `is_a()` check.
2. Keep definitions whose rules all match the runtime context.
3. Prefer the **closest subject class** (exact class, then parent, then interface).
4. Then pick the one with the **most rules** (highest specificity).
5. On tie, pick the highest **priority**.
6. Throw `WorkflowAmbiguousMatchException` if still tied.

Context parameter values are normalized to strings when the `WorkflowContext` is built: ints/floats are cast (`1500` → `"1500"`), booleans become `"true"` / `"false"`, `Stringable` objects are cast, `null` values are dropped; arrays and other objects throw `InvalidArgumentException`.

## Runtime API

```php
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;

// Explicit context
$definition = $resolver->resolve(new WorkflowContext(
    subjectClass: DemoExpense::class,
    parameters: ['tenant' => 'acme', 'department' => 'finance'],
));

// Subject implementing WorkflowContextAwareInterface
$applicator->applyForSubject($expense, 'approve');
$applicator->getEnabledTransitionsForSubject($expense);
$slug = $resolver->resolveSlugForSubject($expense);
```

## Extending entities

- Add `metadata` JSON on `WorkflowDefinition` / `WorkflowMatchRule` for custom data.
- Subclass entities in your app (Doctrine inheritance) if you need extra columns.
- Implement `WorkflowContextAwareInterface` on domain subjects to expose lookup parameters.

## Protecting the CRUD UI

By default every request to `nowo_workflow_*` routes is allowed. Implement `WorkflowUiAccessCheckerInterface` and alias it in the container to enforce your own access policy (roles, IP allowlist, etc.). See [SECURITY.md](SECURITY.md#protecting-the-crud-ui).

## Custom workflow registry

`WorkflowRegistryInterface` is the extension point for resolving Symfony `WorkflowInterface` instances. The default implementation is `DatabaseWorkflowRegistry`; `WorkflowApplicator` depends on the interface.

See demo playgrounds: 0-param orders, 1-param documents, 2-param expenses, 3-param purchase orders, plus `/playground/resolver`.

## FrankenPHP / long-running workers

`DatabaseWorkflowRegistry` memoizes built workflows only for the **current main request** (and implements `ResetInterface`). Edits in one worker are visible to every worker on the next request; outside HTTP (CLI, Messenger) nothing is memoized. Prefer keeping Symfony's `services_resetter` enabled so Doctrine's identity map is cleared between requests; the bundle never calls `EntityManager::clear()`. Details: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Overriding Twig templates (REQ-TWIG-001)

The pages follow the UiKit page contract (REQ-UI-003): theme each view type once in UiKit (`nowo_ui_kit.theme`,
`templates/bundles/NowoUiKitBundle/page/*.html.twig`) and every Workflow page follows. For a Workflow-specific need,
point `nowo_workflow.web_ui.templates.<page>` (`dashboard`, `index`, `show`, `new`, `edit`) at a thin template that
extends `@!NowoWorkflowBundle/…` and overrides a data block — see [TEMPLATES.md](TEMPLATES.md). Full copies under
`templates/bundles/NowoWorkflowBundle/` still win (REQ-TWIG-001) but must keep the data block names and the `page`
variable.

## Overriding translations (REQ-I18N-001)

The bundle ships the **`NowoWorkflowBundle`** domain in seven locales (`en`, `es`, `fr`, `it`, `de`, `nl`, `pt`) under
`src/Resources/translations/` and registers that directory as a translator path. Application catalogues in
`translations/` have priority; any key the application does not define falls back to the bundle value.

Procedure:

1. Use the bundle domain **`NowoWorkflowBundle`** (same name, same nesting of keys).
2. Create `translations/NowoWorkflowBundle.<locale>.yaml` (or `.xlf`) in the application, one file per locale you
   override.
3. Copy only the keys you want to change — look them up in the bundle catalogue
   (`vendor/nowo-tech/workflow-bundle/src/Resources/translations/NowoWorkflowBundle.en.yaml`).
4. Clear the cache: `php bin/console cache:clear`. Check with
   `php bin/console debug:translation es --domain=NowoWorkflowBundle --only-missing`.

Example — `translations/NowoWorkflowBundle.es.yaml`:

```yaml
action:
  delete: 'Eliminar definitivamente'
flash:
  deleted: 'Flujo eliminado.'
```

Every other `NowoWorkflowBundle` key (`action.edit`, `flash.created`, …) keeps the bundle translation. To add a locale
the bundle does not ship, create the full catalogue in the application and add the locale to `web_ui.locales`.

## Browser JavaScript

`nowo-workflow.js` (published by `assets:install`, loaded with `asset('js/nowo-workflow.js', 'nowo_workflow')`) is a
small dependency-free script, not a Stimulus controller: the bundle targets hosts without Symfony UX / AssetMapper, so
the pages work with plain `assets:install` (REQ-UX-001).

- Driven only by `data-*` attributes (`data-workflow-collection`, `data-collection-*`, `data-edge`); it defines **no**
  `window.*` API. The only global it reads is the vendored `window.TomSelect` constructor (transitions page).
- No `innerHTML`, `document.write`, `eval` or inline handlers: table cells are built with `textContent`, new collection
  entries are cloned from `<template data-collection-prototype>` and their `__name__` placeholders are numbered in
  attributes / text nodes.
- Initialisation is idempotent (`data-collection-ready`); the document-level listeners (diagram highlight) are
  registered once and live as long as the page. Tom Select instances are destroyed when their editor dialog closes.
- CSP notes: [SECURITY.md](SECURITY.md#browser-javascript-and-csp).

## Forms in Twig (REQ-TWIG-005)

Every UI form — bundle views and the demo apps — is a PHP Symfony Form Type; the bundle form types are FormKit Types (`#[FormKitConfig('workflow')]` + `FormOptionsTrait`, profile `workflow`, see [CONFIGURATION](CONFIGURATION.md)) and `nowo-tech/form-kit-bundle` is a required dependency whose bundle must be registered. They are rendered with `form_start`, the REQ-TWIG-003 child loop and `form_end`; submit / cancel buttons may stay outside the loop:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

CSRF-only actions (delete, logout-style buttons, toggles) use an empty Form Type too, and login forms map `_username` / `_password` / `_csrf_token` (token id `authenticate`) on a Form Type. Host overrides and demos must not reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>` markup: `make check-no-raw-html-form` (part of `make release-check`) fails when it appears.
