# Security — Workflow Bundle

## Scope

This Symfony bundle persists **workflow definitions** in Doctrine, exposes a **built-in CRUD UI** (configurable path, default `/workflow`), and provides services to **resolve and apply** Symfony Workflow transitions at runtime. The UI is **private by default** (`security.allow_unauthenticated: false`).

## Attack surface

- **HTTP CRUD UI** — create/edit/delete workflow definitions, places, transitions, and match rules.
- **Runtime workflow execution** — `WorkflowApplicator`, `DatabaseWorkflowRegistry` on application subjects.
- **CLI** — `nowo:workflow:sync-schema`, `nowo:workflow:seed-demo`.
- **Persistence** — Doctrine entities for definitions; subject class names stored in the database.

## Threats and mitigations

| Threat | Mitigation |
|--------|------------|
| **Unauthorized CRUD access** | Default `RoleBasedWorkflowUiAccessChecker` with `security.access_roles` (typically `ROLE_ADMIN`). Optional custom `security.access_checker`. Host **MUST** also add Symfony `access_control` for `ui.path`. `allow_unauthenticated: true` is **demo/dev only**. |
| **SQL injection** | Use Doctrine ORM only; no raw SQL with user input. |
| **XSS in admin UI** | Twig auto-escaping; validate user-supplied labels in forms. |
| **Unsafe subject classes** | Validate `subject_class` refers to expected application entities; document trust boundaries for match rules. |
| **CSRF** | Symfony forms include CSRF tokens on mutating actions. |
| **Denial of service** | Limit definition complexity; tune DB and HTTP timeouts at infrastructure level. |

## Secrets

- Database credentials belong in **environment variables**, not committed `.env` files with real secrets.
- The bundle does not embed third-party API keys.

## Reporting a vulnerability

Report security issues **privately** to hectorfranco@nowo.tech. Do not open public GitHub issues for sensitive bugs.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current. |
| **`.gitignore` and `.env`** | Real secrets not committed; demos use `.env.example`. |
| **No secrets in repo** | No production DB passwords or tokens in tracked files. |
| **Recipe / Flex** | Default recipe values are safe (`allow_unauthenticated: false`). |
| **Input / output** | Forms validated; Twig escaping in UI; ORM for persistence. |
| **Dependencies** | `composer audit` clean or documented. |
| **Permissions / exposure** | `access_roles` + firewall `access_control` for `ui.path`; never ship demos with `allow_unauthenticated: true` on a public host. |
| **CLI access** | Restrict who can run schema sync / seed commands in production. |

Record confirmation in the release PR or tag notes.

## Protecting the CRUD UI

### Canonical config

```yaml
nowo_workflow:
    ui:
        path: '/workflow'
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\WorkflowUiAccessChecker
        allow_unauthenticated: false
```

| Key | Default | Notes |
| --- | ------- | ----- |
| `security.access_roles` | `[ROLE_ADMIN]` | At least one role required. Empty = no bundle-level role check. |
| `security.access_checker` | `null` | Custom service id implementing `WorkflowUiAccessCheckerInterface`. |
| `security.allow_unauthenticated` | `false` | Without SecurityBundle, compilation **fails** unless this is `true` or a custom checker is set. **Never `true` in production.** |
| `ui.required_roles` | `[ROLE_ADMIN]` | BC alias mirrored to/from `security.access_roles`. |

### Two layers

1. **Host firewall** — protect the path prefix:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/workflow, roles: ROLE_ADMIN }
```

2. **Bundle checker** — `WorkflowUiAccessSubscriber` enforces `WorkflowUiAccessCheckerInterface` on routes named `nowo_workflow_*`. With SecurityBundle and no custom checker, `RoleBasedWorkflowUiAccessChecker` is wired automatically from `access_roles`.

### Custom checker

```php
// src/Security/WorkflowUiAccessChecker.php
namespace App\Security;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class WorkflowUiAccessChecker implements WorkflowUiAccessCheckerInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function isGranted(Request $request): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }
}
```

```yaml
nowo_workflow:
    security:
        access_checker: App\Security\WorkflowUiAccessChecker
```

### Demos

The Symfony 8 demo sets `security.allow_unauthenticated: true` so it can run without SecurityBundle. Do **not** copy that flag into production. Prefer installing `symfony/security-bundle` and keeping `allow_unauthenticated: false`.
