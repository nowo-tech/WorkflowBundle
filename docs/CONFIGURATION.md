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
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable bundle services |
| `connection` | `default` | Doctrine connection for entities |
| `table_prefix` | `workflow_` | Prefix for Doctrine table and constraint names (`workflow_definition` → `{prefix}definition`) |
| `ui.path` | `/workflow` | Base path for CRUD UI |
| `ui.default_locale` | `en` | Default locale for the CRUD UI |
| `ui.locales` | `en`, `es`, `fr`, `it` | Enabled locales for the locale switcher |

## Protecting the CRUD UI

The bundle does not enforce authentication by default. To restrict access to routes named `nowo_workflow_*`, register a service implementing `WorkflowUiAccessCheckerInterface`. See [SECURITY.md](SECURITY.md#protecting-the-crud-ui).
