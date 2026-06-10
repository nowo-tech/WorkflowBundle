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

See demo playgrounds: 0-param orders, 1-param documents, 2-param expenses, 3-param purchase orders, plus `/playground/resolver`.
