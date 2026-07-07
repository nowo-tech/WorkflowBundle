# Configuration

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    enabled: true
    connection: default
    table_prefix: workflow_
    ui:
        path: '/workflow'
        default_locale: en
        locales: [en, es, fr, it]
        required_roles: [ROLE_ADMIN]
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable bundle services |
| `connection` | `default` | Doctrine connection for entities |
| `table_prefix` | `workflow_` | Prefix for Doctrine table and constraint names (`workflow_definition` → `{prefix}definition`) |
| `ui.path` | `/workflow` | Base path for CRUD UI |
| `ui.default_locale` | `en` | Default locale for the CRUD UI |
| `ui.locales` | `en`, `es`, `fr`, `it` | Enabled locales for the locale switcher |
| `ui.required_roles` | `ROLE_ADMIN` | Documented roles for Flex/recipe wiring of `RoleBasedWorkflowUiAccessChecker` (does not auto-register the checker) |

Shipped translation catalogs: `en`, `es`, `fr`, `it`, `de`, `nl`, `pt`. Add `de`, `nl`, or `pt` to `ui.locales` to expose them in the switcher.

## Protecting the CRUD UI

The bundle does not enforce authentication by default. To restrict access to routes named `nowo_workflow_*`:

1. Register a service implementing `WorkflowUiAccessCheckerInterface`, **or**
2. Use the built-in `RoleBasedWorkflowUiAccessChecker` when Symfony Security is installed (see [SECURITY.md](SECURITY.md#protecting-the-crud-ui)).
