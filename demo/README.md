# Symfony 8 demo for Workflow Bundle (FrankenPHP).

```bash
make up-symfony8
```

Open http://localhost:8022 (see `symfony8/.env.example` for `PORT`).

## Scenarios

1. **Workflow CRUD** — `/workflow` — manage definitions, places, and transitions in the database.
2. **Order approval** — state machine on `DemoOrder` with transitions submit/approve/reject/reopen.
3. **Document review** — parallel workflow on `DemoDocument`.

## Commands

```bash
make -C symfony8 shell
php bin/console nowo:workflow:sync-schema
php bin/console nowo:workflow:seed-demo --fresh
php bin/console doctrine:schema:update --force
```
