# Multitenant workflows (1.15)

Organization-scoped workflow definitions for hosts such as Beacon: each organization runs its own version of a
workflow (leave approval, working-time corrections, access requests…), customized from a global template, edited in an
embeddable UI under the organization's settings, with per-transition guards configured by the organization's admins
and an audit event per applied transition.

Everything here is **opt-in**: without `nowo_workflow.tenancy.tenant_resolver` the bundle behaves exactly as 1.14
(see [Without tenancy](#without-tenancy)).

## Table of contents

- [Tenant model](#tenant-model)
- [Versions](#versions)
- [Runtime](#runtime)
- [Embeddable UI](#embeddable-ui)
- [Transition guards](#transition-guards)
- [Audit](#audit)
- [GDPR](#gdpr)
- [Worker mode](#worker-mode)
- [Standard Symfony host](#standard-symfony-host)
- [DDD / hexagonal host](#ddd--hexagonal-host)
- [Beacon example: leave approval](#beacon-example-leave-approval)
- [Without tenancy](#without-tenancy)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Security notes](#security-notes)

## Tenant model

- A definition belongs to one **tenant** (an opaque key of the host: an organization UUID, a workspace slug) or to
  **no tenant**: then it is a **global template**.
- **Exact keys (audit NO M1).** A tenant key (and a subject reference) is 1 to 128 printable ASCII characters
  without spaces, so no accent and no trailing space can make two keys equal under a database collation. Keys are
  compared exactly: every row the repositories and the pin store read is re-checked in PHP (`acme` is never `Acme`);
  on MySQL / MariaDB the `tenant_key` and `subject_ref` columns use the binary collation `utf8mb4_bin` (applied by
  the schema tools; other platforms already compare them exactly). Use the canonical form of your keys (e.g.
  lower-case UUIDs) everywhere.
- `Model\WorkflowTenant` is the value object; the database column `tenant_key` stores `''` for a global template.
- The slug is unique per tenant (and version): a tenant may customize a template under the same slug, so host
  listeners on `workflow.<slug>.*` keep working for every tenant.
- The tenant of a **subject** comes from the host port `Contract\WorkflowTenantResolverInterface`
  (`tenantOf(WorkflowSubject): ?WorkflowTenant`), asked on every call (never cached).

**Resolution** for a subject and a slug (deny by default):

1. the version the subject is pinned to (see [Versions](#versions));
2. else the tenant's current published version of the slug;
3. else the global template's current published version.

A definition of another tenant is **never** used, listed, shown, edited or cloned. A tenant that **disabled** its
own version opted out: there is no fallback to the template (`WorkflowNotFoundException`). A subject whose tenant is
null only runs global templates.

## Versions

A published version is **never edited**. The lifecycle of each `(tenant, slug)`:

| Step | What happens | UI action (permission) |
|------|--------------|------------------------|
| Clone a template | The template's current published version is copied into the tenant as **draft v1** (`template_version` records which template version it came from). | "Customize for this organization" (`edit`) |
| New draft | The current published version is copied as the **next version number**, status draft. One open draft at a time. | "New draft" (`edit`) |
| Edit | Only drafts are edited (general, match rules, places, transitions and their guards). The slug is fixed once created. | Edit (`edit`) |
| Publish | The draft becomes the version **new subjects** start with. | Publish (`publish`) |
| Delete | A draft: only the draft. A published definition: every version of the slug in that scope, **refused while any subject is pinned to one of them** (owner decision 2026-11-12: `WorkflowDefinitionInUseException`, the UI shows the count and suggests disabling instead). | Delete (`publish`) |

**In-flight subjects keep the version they started with.** When `WorkflowApplicator` (or the runtime port) applies a
transition to a subject for the first time, the bundle stores a **pin**: `(subject's tenant, slug, subject reference)
→ definition version` in `workflow_subject_pin`. Every later call for that subject runs the pinned version, even after
a newer version is published or the tenant customizes the template. The subject reference comes from
`Contract\WorkflowSubjectReferenceResolverInterface` (`referenceOf(WorkflowSubject): ?WorkflowSubjectRef`, e.g. the
subject's UUID).

Pinning rules:

- without a subject reference resolver (or for a subject it returns null for, e.g. not persisted yet) nothing is
  pinned: the subject follows the current version (**"follow latest"**). Its marking must then exist in the new
  version, otherwise Symfony refuses the marking (`LogicException`): only remove places when every subject has left
  them, or configure the resolver;
- the subject is pinned **before** its first transition is applied (audit NO M3): two concurrent first applies agree
  on one version (the first pin wins; the loser runs the winner's version). Pins are written with plain DBAL
  statements on the definitions' connection: inside a transaction your application opened, the insert runs in a
  savepoint (a duplicate never aborts your transaction, PostgreSQL included) and commits or rolls back with it;
  outside one (e.g. `runtime.flush_after_apply: false` before you flush) it commits on its own, even if you never
  persist the subject;
- a pin is honoured only when it names that slug, a published version, and the subject's tenant or a global
  template. Anything else (a tampered row, a subject moved to another tenant) is **refused**
  (`WorkflowNotFoundException`), never followed;
- a published definition subjects are pinned to cannot be deleted (disable it instead); `forgetSubject()` removes a
  subject's pins, after which the definition can be deleted;
- no automatic marking migration: a subject moves to a newer version only when you remove its pin
  (`WorkflowVersioning::forgetSubject()`), and then the "follow latest" rule above applies.

The versioning operations are also a service for hosts: `Service\WorkflowVersioning` (`cloneTemplate()`,
`createDraft()` / `createDraftOf()`, `publishDraft()` / `publish()`, `delete()`, `forgetSubject()`; the UI acts on the
already authorised entity, never on a new lookup by slug and tenant), e.g. to give each new organization a published copy
of a template: `$versioning->cloneTemplate('leave', new WorkflowTenant($uuid), publish: true)`.

## Runtime

Call the framework-free runtime port `Contract\WorkflowRuntimeInterface` (1.15):

```php
$result = $runtime->apply($leaveRequest, 'leave', 'submit');   // WorkflowTransitionResult
$result->applied;                // false when a guard blocked it: $result->blockerCodes says why
$result->automaticTransitions;   // e.g. ['auto_approve']
$result->marking->places;        // ['approved']
$runtime->can($leaveRequest, 'leave', 'approve');
$runtime->enabledTransitions($leaveRequest, 'leave');
$runtime->marking($leaveRequest, 'leave');   // WorkflowMarkingView (version and tenant of the definition that runs)
```

`WorkflowApplicator` keeps its 1.14 API and resolves the same way. `DatabaseWorkflowRegistry::forSubject($subject,
$slug)` returns the Symfony workflow of the version the subject runs. `WorkflowRegistryInterface::get($slug)` only knows
global templates: with tenancy, use the runtime port.

**Defence in depth:** with tenancy every database workflow carries the id of its stored version, and a guard listener
(priority 2048) blocks any transition when that version is not the one the resolution above picks for the subject
(code `nowo_workflow.tenant_mismatch`). So a workflow obtained another way (`get()`, a workflow kept from another
subject) can never move a tenant's subject.

## Embeddable UI

Mount the UI under the host's route with variables through `web_ui.path_prefix`:

```yaml
nowo_workflow:
    web_ui:
        path_prefix: '/organizations/{uuid}/settings/workflows'
```

```yaml
# config/routes/nowo_workflow.yaml
nowo_workflow:
    resource: '@NowoWorkflowBundle/Resources/config/routes.yaml'
```

The prefix variables (`uuid`) are router context parameters while a Workflow UI request runs, so every link and
redirect of the bundle keeps them; they are removed when the request finishes.

Two host ports (both **required** with tenancy and the UI enabled, the build fails otherwise):

- `Contract\WorkflowUiTenantResolverInterface::tenantFor(WorkflowUiAccessCheck $request): ?WorkflowTenant` — the scope
  of the request, from `$request->routeParam('uuid')`. Null = the global templates scope (platform admins).
- `Contract\WorkflowUiActionCheckerInterface::allows(WorkflowUiActionCheck $check): bool` — may the actor do this
  action (`WorkflowUiAction::View`, `Edit`, `Publish`) in this scope? The check carries the scope, the definition's
  slug and tenant (a template seen from a tenant has `definitionTenant` null), and the 1.14 request VO (actor, route,
  path).

The 1.14 `WorkflowUiAccessPolicyInterface` still decides first, for every Workflow UI route; the action checker is
asked on top (both must grant). Without tenancy the action checker is optional and asked the same way.

What each scope sees:

| Scope | List / dashboard | Show / diagram | Edit, publish, delete | Clone |
|-------|------------------|----------------|-----------------------|-------|
| Tenant T | T's definitions (latest version, draft or published) + the published templates T has not customized | T's and published templates | T's only (drafts; published versions are read-only) | published templates into T |
| Global (null) | global definitions (latest version) | global | global | — |
| Another tenant's definition | never listed | 404 | 404 | 404 |
| A template from a tenant | listed with a "Global template" badge | 200 | 403 | allowed |

The editor shows the guard fields of a transition only when their port is configured (below).

## Transition guards

Configured per transition in the editor (stored on `workflow_transition`), enforced by a `workflow.guard` listener for
every way the workflow is used. **Fail closed:** a guard whose port is not configured, a port that says no, a condition
that is false or cannot be evaluated all block the transition.

**Permission** (`guards.permission_checker`): "only someone with permission X in the subject's context". The editor
types an opaque permission (`leave.approve`); the host's
`Contract\WorkflowTransitionPermissionCheckerInterface::isGranted(WorkflowTransitionPermissionCheck)` decides with the
permission, the subject reference, the subject's tenant, the actor (`Contract\WorkflowActorProviderInterface`, default:
the security token's user identifier) and the workflow / transition names. Decide in the subject's context (e.g. "is
the actor a manager of this employee's team in this organization"), not with global Symfony roles. Blocker code
`nowo_workflow.permission_denied`.

**Condition** (`guards.variables_provider`): a tiny, closed condition language over the host's **whitelist** of scalar
variables:

```
days <= 2 and type == 'vacation'
not (urgent or days > 10)
manager != null
```

- `Contract\WorkflowSubjectVariablesProviderInterface::variableNames($subjectClass)` lists the variables a condition may
  use (validated when the editor saves); `variablesOf(WorkflowSubject)` returns their values as a
  `Model\WorkflowSubjectVariables` (scalars and null only).
- Grammar: comparisons (`== != < <= > >=`) of variables with numbers, `'strings'`, `true`, `false`, `null`; `and`,
  `or`, `not`, parentheses. No function, method, property, index, arithmetic, concatenation or escape sequence: a
  condition can only compare whitelisted scalars with literals. It is **not** Symfony ExpressionLanguage.
- Strict types: numbers compare with numbers; strings and booleans only with `==` / `!=` of the same type; `null` with
  `==` / `!=`. A type mismatch, a missing variable or a non-boolean condition is an error, and an error blocks.
- Limits: 500 bytes, 128 tokens, nesting depth 16.
- Blocker code `nowo_workflow.condition_not_met`; evaluation errors are logged (message only, no values).

**Automatic** transitions: after every applied transition, `WorkflowApplicator` (and the runtime port) applies the
enabled automatic transitions one after the other (alphabetical order when several are enabled), e.g. `auto_approve`
with the condition `days <= 2`. They are **idempotent** (they depend only on the marking and the guards; calling
`applyAutomaticTransitions()` again does nothing) and **loop-safe**: the run stops when a marking repeats or after
`guards.max_automatic_transitions` steps (default 10), with a warning. A definition without automatic transitions
evaluates no extra guard. After creating a subject whose initial place has an automatic transition, call
`$applicator->applyAutomaticTransitions($subject)`.

## Audit

With `audit.enabled` (default: on with tenancy) the bundle dispatches `Event\WorkflowTransitionAppliedEvent` for every
transition a database workflow completes (including automatic ones and workflows used directly), **ids only**:
workflow slug, definition version and tenant, transition, from / to places, subject class, subject reference, actor
reference, subject's tenant, `automatic` flag, time.

It is dispatched **after the commit** of the transaction that persisted the subject (REQ-ARCH-001 rule 7):

- inside a transaction (yours, or the flush of `runtime.flush_after_apply: true`), it waits for the outermost commit;
  a rollback (or a rolled-back savepoint) drops it;
- with `runtime.flush_after_apply: false` it waits for the next transaction that begins (your flush) and follows it;
- committed events are dispatched right after `WorkflowApplicator` flushed, or at the end of the request, console
  command or Messenger message (not from a Doctrine `postFlush`, audit NO M4);
- whatever is not committed by the end of the request, console command or Messenger message (a marking never
  persisted, or a transaction still open) is dropped with a warning (audit NO L1).

Transactions are observed by a DBAL 4 middleware on the connections of the definitions' manager and of your default
manager (where `WorkflowApplicator` persists the subject) only; the audit needs doctrine/dbal 4. A subject persisted
on another connection is not tracked: its events wait for the next tracked transaction or are dropped at the
boundary. This is best effort around your transactions, not a guarantee: forward the event to an outbox if you need
exactly-once delivery. A listener that throws is logged and does not affect the commit that already happened.

## GDPR

The bundle stores **no actor id** and no personal data of its own: the audit event is dispatched, never stored. The
only per-subject rows are the version pins: subject's tenant key, workflow slug, the host's **subject reference** and
the definition id. Use a business id as subject reference (a leave request UUID), never a name or an e-mail. When you
delete or anonymise a subject, remove its pins:

```php
$versioning->forgetSubject(new WorkflowSubjectRef($leave->uuid()), new WorkflowTenant($organizationUuid));
```

No UserKit export / erasure provider is registered (nothing is keyed by a user).

## Worker mode

FrankenPHP worker mode with the kernel **not** reset (REQ-RUNTIME-002): every tenancy port is asked per call, nothing
about a tenant, subject or actor is kept in a service. Built workflows are memoized per main request only. The audit
dispatcher dispatches or drops everything at each boundary (request, console command, Messenger message) and is reset
on `kernel.reset`. Tested by `TenancyWorkerKernelTest` (1,000 rounds alternating two organizations in one kernel, no
services resetter, flat memory).

## Standard Symfony host

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    runtime:
        flush_after_apply: false
    web_ui:
        path_prefix: '/organizations/{uuid}/settings/workflows'
    tenancy:
        tenant_resolver: App\Workflow\WorkflowTenancy
        subject_reference_resolver: App\Workflow\WorkflowTenancy
        subject_classes:
            App\Leave\LeaveRequest: status
        ui:
            tenant_resolver: App\Workflow\WorkflowTenancy
            action_checker: App\Workflow\WorkflowTenancy
    guards:
        permission_checker: App\Workflow\WorkflowTenancy
        variables_provider: App\Workflow\WorkflowTenancy
```

One class can implement every port. Update the schema (`doctrine:migrations:diff` or `nowo:workflow:sync-schema`).

## DDD / hexagonal host

- Every port takes and returns bundle value objects (`WorkflowSubject`, `WorkflowTenant`, `WorkflowSubjectRef`,
  `WorkflowActorRef`, `WorkflowSubjectVariables`, `WorkflowUiActionCheck`…): no Doctrine, HttpFoundation or Security
  type. Implement them in your infrastructure layer over your own application services.
- Call `WorkflowRuntimeInterface` from your application services; it returns VOs (`WorkflowTransitionResult`,
  `WorkflowMarkingView`).
- Map the bundle's entities from the shipped XML (`doctrine.mapping_type: xml` or `register_mapping: false` with your
  own mapping of `src/Resources/config/doctrine`, which now includes `WorkflowSubjectPin.orm.xml`) on a dedicated
  manager if you like (`doctrine.entity_manager`); your subjects stay on your own manager. Tested by
  `RuntimePortKernelTest::aDddHostRunsTenantWorkflowsThroughTheRuntimePortOnly` (two connections, XML mapping, plain
  PHP subject).
- Forward `WorkflowTransitionAppliedEvent` to your audit log from a listener (it is already after the commit).

## Beacon example: leave approval

Spec 204: an employee asks for leave; short leave is approved automatically, longer leave needs a manager of the
employee's organization.

Global template `leave` (created once by a platform admin in the global scope, then published):

| Transition | From → To | Guard |
|------------|-----------|-------|
| `submit` | requested → submitted | — |
| `auto_approve` | submitted → approved | automatic, condition `days <= 2` |
| `approve` | submitted → approved | permission `leave.approve` |
| `reject` | submitted → rejected | permission `leave.approve` |

An organization that allows three days without approval clicks "Customize for this organization", edits the condition
of `auto_approve` to `days <= 3` and publishes: its new requests use v1 of its own copy; requests already submitted
keep the template version they started with.

```php
final readonly class WorkflowTenancy implements WorkflowTenantResolverInterface, WorkflowSubjectReferenceResolverInterface,
    WorkflowUiTenantResolverInterface, WorkflowUiActionCheckerInterface, WorkflowTransitionPermissionCheckerInterface,
    WorkflowSubjectVariablesProviderInterface
{
    public function __construct(private OrganizationAccess $access, private LeaveDirectory $leaves) {}

    public function tenantOf(WorkflowSubject $subject): ?WorkflowTenant
    {
        return $subject->object instanceof LeaveRequest ? new WorkflowTenant($subject->object->organizationId()->toString()) : null;
    }

    public function referenceOf(WorkflowSubject $subject): ?WorkflowSubjectRef
    {
        return $subject->object instanceof LeaveRequest ? new WorkflowSubjectRef($subject->object->id()->toString()) : null;
    }

    public function tenantFor(WorkflowUiAccessCheck $request): ?WorkflowTenant
    {
        $uuid = $request->routeParam('uuid');

        return is_string($uuid) ? new WorkflowTenant($uuid) : null;   // route always has {uuid}: no global scope here
    }

    public function allows(WorkflowUiActionCheck $check): bool
    {
        $user = $check->request->actor?->userIdentifier;

        return $user !== null && $check->tenant !== null && match ($check->action) {
            WorkflowUiAction::View    => $this->access->can($user, $check->tenant->key, 'workflows.view'),
            WorkflowUiAction::Edit    => $this->access->can($user, $check->tenant->key, 'workflows.edit'),
            WorkflowUiAction::Publish => $this->access->can($user, $check->tenant->key, 'workflows.publish'),
        };
    }

    public function isGranted(WorkflowTransitionPermissionCheck $check): bool
    {
        return $check->permission === 'leave.approve' && $check->actor !== null && $check->subject !== null
            && $this->leaves->isManagerOf($check->actor->id, $check->subject->id);
    }

    public function variableNames(string $subjectClass): array
    {
        return $subjectClass === LeaveRequest::class ? ['days', 'type'] : [];
    }

    public function variablesOf(WorkflowSubject $subject): WorkflowSubjectVariables
    {
        $leave = $subject->object;

        return new WorkflowSubjectVariables($leave instanceof LeaveRequest ? ['days' => $leave->workingDays(), 'type' => $leave->type()->value] : []);
    }
}
```

```php
// Application service
$result = $this->workflows->apply($leave, 'leave', 'submit');
$this->leaves->save($leave);                     // runtime.flush_after_apply: false
// WorkflowTransitionAppliedEvent(s) go out after this commit: forward them to AuditKit.
```

The same shape serves working-time corrections, Vault access requests and, later, e-mail quarantine: one template per
process, customized per organization.

## Without tenancy

Without `tenancy.tenant_resolver`:

- every definition is global, version 1, published, edited in place (exactly 1.14);
- no isolation guard, no audit middleware or event (unless `audit.enabled: true`), no pins;
- the UI has no draft, publish or clone action and no guard field (unless a guard port is configured);
- `WorkflowRuntimeInterface` and `forSubject()` resolve as `WorkflowRegistryInterface::get()`.

Tested by `NoResolverEquivalenceKernelTest` (same workflow events in the same order as the 1.14 applicator, same
markings, and the tenancy-only services (isolation guard, audit dispatcher and middleware) are not registered).

## Owner decisions (2026-11-12)

1. **Versioning model:** one row per version, subjects pinned to the version they started with; no automatic marking
   migration.
2. **A disabled tenant version** does not fall back to the global template (the tenant opted out).
3. **Deleting** a published tenant definition is refused while any subject is pinned to one of its versions (the UI
   shows the count and suggests disabling it); drafts can always be deleted; `forgetSubject()` removes a subject's
   pins.
4. **`WorkflowRegistryInterface`** is deprecated for hosts (removed in 2.0), not made `@internal`.
5. **The audit event** is on by default with tenancy and needs doctrine/dbal 4.
6. **GDPR:** no UserKit export / erasure providers: nothing is stored per user.

## Security notes

- Tenant keys are compared exactly (PHP re-check, binary collation on MySQL); never derive the UI scope from a value
  the user can choose without your action checker validating it (the route `{uuid}` is user input: `allows()` must
  check the actor belongs to it).
- **Tenant-authored workflows reach your listeners.** A tenant admin edits places, transitions and guards of its own
  definitions; your listeners on `workflow.<slug>.*` and `workflow.guard` receive those workflows too. In a tenant
  scope the runtime binding is fixed (audit NO M2): type, subject class and marking property come from the cloned
  template or, for a new definition, from `tenancy.subject_classes` (`{Class: marking_property}`; a class outside it
  is refused), and place / transition names must match `^[a-z0-9_]{1,64}$`. Do not trust place or transition names
  for authorization: check the subject and the actor.
- A guard port that throws blocks the transition (fail closed); the exception class is logged, never its message.
- The isolation guard resolves the subject's version on every guard event (1–3 indexed queries); it is not memoized
  (a memo would be per-request state).
- The action checker sees every action, including `View`: deny unknown actors.
- A tenant can only clone published templates; it never sees global drafts.
- Conditions only read the variables your provider returns: never return secrets there, they become comparable by
  anyone who can edit a definition of that tenant.
