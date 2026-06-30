# Usage

## Match rules and resolution

Each `WorkflowDefinition` can define zero or more `WorkflowMatchRule` rows (`parameter_key` + `parameter_value`).

- **0 rules** — default workflow for the subject class when nothing more specific matches.
- **1 rule** — e.g. `document_type=invoice`.
- **2+ rules** — all must match (AND), e.g. `tenant=acme` + `department=finance`.

Resolution order:

1. Filter enabled definitions by subject class.
2. Keep definitions whose rules all match the runtime context.
3. Pick the one with the **most rules** (highest specificity).
4. On tie, pick the highest **priority**.
5. Throw `WorkflowAmbiguousMatchException` if still tied.

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

## Overriding Twig templates (REQ-TWIG-001)

Place files under `templates/bundles/NowoWorkflowBundle/` with the same relative path as in `src/Resources/views/`:

```
templates/bundles/NowoWorkflowBundle/layout.html.twig
templates/bundles/NowoWorkflowBundle/dashboard/index.html.twig
templates/bundles/NowoWorkflowBundle/workflow_definition/index.html.twig
```

Application overrides always win. Render using `@NowoWorkflowBundle/...` logical names.

## Overriding translations (REQ-I18N-001)

Translation domain: **`NowoWorkflowBundle`**. Override in the application:

```
translations/NowoWorkflowBundle.en.yaml
translations/NowoWorkflowBundle.es.yaml
```

Clear cache after adding overrides: `php bin/console cache:clear`.
