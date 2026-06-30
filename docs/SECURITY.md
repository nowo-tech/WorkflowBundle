# Security — Workflow Bundle

## Scope

This Symfony bundle persists **workflow definitions** in Doctrine, exposes a **built-in CRUD UI** (configurable path, default `/workflow`), and provides services to **resolve and apply** Symfony Workflow transitions at runtime. Production applications should protect the UI via `WorkflowUiAccessCheckerInterface` and/or Symfony Security before exposing it publicly.

## Attack surface

- **HTTP CRUD UI** — create/edit/delete workflow definitions, places, transitions, and match rules.
- **Runtime workflow execution** — `WorkflowApplicator`, `DatabaseWorkflowRegistry` on application subjects.
- **CLI** — `nowo:workflow:sync-schema`, `nowo:workflow:seed-demo`.
- **Persistence** — Doctrine entities for definitions; subject class names stored in the database.

## Threats and mitigations

| Threat | Mitigation |
|--------|------------|
| **Unauthorized CRUD access** | Implement `WorkflowUiAccessCheckerInterface` (see below) or restrict `/workflow` routes with Symfony Security (`access_control`, roles). Without a custom checker, the UI is open by default. |
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
| **Recipe / Flex** | Default recipe values are safe. |
| **Input / output** | Forms validated; Twig escaping in UI; ORM for persistence. |
| **Dependencies** | `composer audit` clean or documented. |
| **Permissions / exposure** | Document required roles for CRUD UI; provide `WorkflowUiAccessCheckerInterface` when Symfony Security is not used. |
| **CLI access** | Restrict who can run schema sync / seed commands in production. |

Record confirmation in the release PR or tag notes.

## Protecting the CRUD UI

By default the bundle allows all requests to routes named `nowo_workflow_*`. To protect the UI without configuring `access_control`, register a custom checker:

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
# config/services.yaml
services:
    Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface:
        class: App\Security\WorkflowUiAccessChecker
```

If no service is aliased to the interface, `AllowAllWorkflowUiAccessChecker` is used (open access, backward compatible).
