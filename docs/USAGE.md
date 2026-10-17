# Usage

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

Translation domain: **`NowoWorkflowBundle`**. Override in the application:

```
translations/NowoWorkflowBundle.en.yaml
translations/NowoWorkflowBundle.es.yaml
```

Clear cache after adding overrides: `php bin/console cache:clear`.
