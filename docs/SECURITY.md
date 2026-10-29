# Security — Workflow Bundle

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Browser JavaScript and CSP](#browser-javascript-and-csp)
- [Dependencies and updates](#dependencies-and-updates)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Protecting the CRUD UI](#protecting-the-crud-ui)
  - [Canonical config](#canonical-config)
  - [Two layers](#two-layers)
  - [Custom checker](#custom-checker)
  - [Demos](#demos)

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
| **Unauthorized CRUD access** | Default `RoleBasedWorkflowUiAccessChecker` with `security.access_roles` (typically `ROLE_ADMIN`). Optional custom `security.access_checker`. Host **MUST** also add Symfony `access_control` for `web_ui.path_prefix`. `allow_unauthenticated: true` is **demo/dev only**. |
| **SQL injection** | Use Doctrine ORM only; no raw SQL with user input. |
| **XSS in admin UI** | Twig auto-escaping; validate user-supplied labels in forms. |
| **Unsafe subject classes** | Validate `subject_class` refers to expected application entities; document trust boundaries for match rules. |
| **Fail-open when disabled** | `enabled: false` makes every `nowo_workflow_*` route answer **404** (request listener right after the router); the access checker is still wired (fail-closed `DenyAllWorkflowUiAccessChecker` when no security is available). |
| **Open redirect (locale switch)** | `/locale/{_locale}` only redirects to a same-host `Referer` (or a local path); anything else goes to the dashboard. |
| **CSRF** | Create / edit forms carry Symfony form CSRF tokens; delete is a POST checked with `isCsrfTokenValid('delete<id>')`. `CsrfProtectionPass` fails closed: with `web_ui.enabled: true` the container refuses to compile when `security.csrf.token_manager` or the form CSRF extension is missing, or `framework.form.csrf_protection` is disabled. |
| **DOM XSS in the shipped JS** | `nowo-workflow.js` never parses HTML strings: field values reach the table as text nodes, new collection entries are cloned from a server-rendered `<template>` (no `innerHTML` / `document.write` / `eval`). |
| **Denial of service** | The definitions list is paginated (`web_ui.list_page_size`, default 20) with a bounded query count; built workflows are memoized per main request only. Limit definition complexity; tune DB and HTTP timeouts at infrastructure level. |

## Secrets and cryptography

- Database credentials belong in **environment variables**, not committed `.env` files with real secrets.
- The bundle does not embed third-party API keys and stores no secrets.
- No cryptography of its own: CSRF tokens come from Symfony's token manager; the bundle signs or encrypts nothing.

## Logging

The bundle logs through an injected PSR-3 `Psr\Log\LoggerInterface` (the host `logger` service, a `NullLogger` when none
is wired) with structured context (`bundle`, `action`, …); it never uses `dump()` / `error_log()`.

| Event | Level | Context |
| ----- | ----- | ------- |
| `nowo:workflow:sync-schema` start / finish / failure | info / info / error | `connection`, statement counts (`executed`, `skipped`), `exception` on failure |
| `nowo:workflow:seed-demo` start / finish / failure (`--fresh` deletes every definition) | info / info / error | `fresh`, `exception` on failure |
| Definition deleted from the UI | info | definition `id`, `slug` |
| Delete rejected (invalid CSRF token) | warning | definition `id` |

**Never logged:** SQL parameters, CSRF tokens, session ids, request bodies, user names or any personal data. Workflow
slugs and ids are configuration identifiers, not personal data.

## Browser JavaScript and CSP

- The pages load only same-origin files published by `assets:install` (`nowo-workflow.js`, `nowo-workflow.css`,
  vendored Tom Select) through the `nowo_workflow` asset package — no CDN, no inline `<script>` / `style` attributes
  added by the bundle, no inline event handlers, no `eval` / `new Function`.
- A strict CSP such as `script-src 'self'; style-src 'self'` works with the bundle pages (UiKit's own requirements apply
  to the layout it renders).
- `nowo-workflow.js` exposes no global API; it only reads the vendored `window.TomSelect` constructor on the
  transitions page. See [USAGE.md](USAGE.md#browser-javascript).

## Dependencies and updates

- `composer audit` runs in CI on every matrix cell (`composer audit --locked`); Dependabot (Composer + GitHub Actions)
  opens grouped update PRs.
- Security fixes are released as patch versions of the supported line (see [`.github/SECURITY.md`](../.github/SECURITY.md)).

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
| **Input / output** | Forms validated; Twig escaping in UI; ORM for persistence; shipped JS builds DOM nodes, never HTML strings. |
| **Dependencies** | `composer audit` clean or documented. |
| **No-secret logs** | Only the events of [Logging](#logging); no tokens, SQL parameters or personal data in log context. |
| **Cryptography** | None of its own (Symfony CSRF token manager only); nothing to rotate. |
| **Permissions / exposure** | `access_roles` + firewall `access_control` for `web_ui.path_prefix`; CSRF fail-closed (`CsrfProtectionPass`); never ship demos with `allow_unauthenticated: true` on a public host. |
| **Limits / DoS** | Paginated definitions list (`web_ui.list_page_size`); per-request workflow memoization only; no unbounded exports. |
| **CLI access** | Restrict who can run schema sync / seed commands in production (`seed-demo --fresh` deletes every definition). |
| **AI security audit (REQ-SEC-004)** | Must pass before release; latest recorded grade in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`: **Pass (good)**, Low risk (re-audit 2026-09-28). |

Record confirmation in the release PR or tag notes.

## Protecting the CRUD UI

### Canonical config

```yaml
nowo_workflow:
    web_ui:
        path_prefix: '/workflow'
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\WorkflowUiAccessChecker
        allow_unauthenticated: false
```

| Key | Default | Notes |
| --- | ------- | ----- |
| `security.access_roles` | `[ROLE_ADMIN]` | At least one non-empty role required. Empty list is rejected at compile time unless `allow_unauthenticated` or a custom `access_checker` is set. |
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

2. **Bundle checker** — `WorkflowUiAccessSubscriber` enforces `WorkflowUiAccessCheckerInterface` on routes named `nowo_workflow_*`; with `enabled: false` those routes return 404. With SecurityBundle and no custom checker, `RoleBasedWorkflowUiAccessChecker` is wired automatically from `access_roles`.

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
