# Configuration

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    enabled: true
    connection: default
    table_prefix: workflow_
    ui:
        path: '/workflow'
```

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable bundle services |
| `connection` | `default` | Doctrine connection for entities |
| `table_prefix` | `workflow_` | Reserved for future table customization |
| `ui.path` | `/workflow` | Base path for CRUD UI |
