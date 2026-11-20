# Architecture

How the bundle is put together for hosts: the ports you call, the SPI you implement, the events you receive, and how a
standard Symfony application and a DDD / hexagonal one wire them (REQ-ARCH-001). Configuration keys are in
[CONFIGURATION.md](CONFIGURATION.md); examples in [USAGE.md](USAGE.md) and [MULTITENANCY.md](MULTITENANCY.md).

## Table of contents

- [Two parts](#two-parts)
- [Ports hosts call](#ports-hosts-call)
- [SPI hosts implement](#spi-hosts-implement)
- [Events](#events)
- [Persistence](#persistence)
- [Standard Symfony and DDD hosts](#standard-symfony-and-ddd-hosts)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Replacing or decorating a port](#replacing-or-decorating-a-port)
  - [Versioning without the aggregate](#versioning-without-the-aggregate)
- [REQ-ARCH-001 status](#req-arch-001-status)
- [Done in 2.0](#done-in-20)

## Two parts

- **Definitions** (places, transitions, match rules, tenant versions, subject pins): a small bounded context of the
  bundle, five bundle-owned entities (`Entity\WorkflowDefinition`, `WorkflowPlace`, `WorkflowTransition`,
  `WorkflowMatchRule`, `WorkflowSubjectPin`) with attribute mapping and the same mapping in XML
  (`src/Resources/config/doctrine/*.orm.xml`). They reference your subjects by class name (`subject_class`) and by an
  opaque subject reference string, never by a foreign key to your tables.
- **The runtime**: builds Symfony workflows from those definitions and moves your subjects. Your subjects never
  extend, use or implement anything of the bundle (an optional `WorkflowContextAwareInterface` exposes match
  parameters).

## Ports hosts call

Every port is an interface aliased in the container to the built-in service, so autowiring by interface works, the
class ids keep working, and you may alias the interface to your own service or decorate it.

| Port | Built-in service | Since | Notes |
|------|------------------|-------|-------|
| `Contract\WorkflowRuntimeInterface` | `Service\WorkflowRuntime` | 1.15 | `can()`, `apply()`, `enabledTransitions()`, `marking()`, `pin()`. Scalars and bundle VOs only (`Model\WorkflowTransitionResult`, `WorkflowMarkingView`, `WorkflowPinResult`). |
| `Contract\WorkflowSlugResolverInterface` | `Service\WorkflowResolver` | 1.17 | `resolveSlug(WorkflowContext)`, `resolveSlugForSubject(object)`: the slug to pass to the runtime port. The bundle's own applicator, runtime and UI still use `WorkflowResolver` directly, so replacing this port only changes what host callers get. |
| `Contract\WorkflowVersionManagerInterface` | `Service\WorkflowVersionManager` | 1.19 | Drafts, publishing, retiring, template clones, `forgetSubject()`, `delete()`, plus the entity-free reads `currentVersion()`, `draftVersion()`, `versions()`. Takes `Model\WorkflowDefinitionRef`, returns `Model\WorkflowDefinitionView`: scalars, bundle enums and bundle VOs only. The only versioning port since 2.0: call it, decorate or replace it. |

`Service\WorkflowApplicator` and `Service\WorkflowResolver`'s entity-returning methods stay public for standard hosts
(final classes, no interface): the applicator works with Symfony Workflow types, so the runtime port is its
framework-free replacement. Removed in 2.0: `Contract\WorkflowRegistryInterface` (returned a Symfony
`WorkflowInterface`; deprecated since 1.15) and `Contract\WorkflowVersioningInterface` (took and returned the
`WorkflowDefinition` entity; deprecated as a port since 1.19). `Service\WorkflowVersioning` is internal since 2.0. A
host service still registered under, aliased to or decorating a removed id fails the build.

## SPI hosts implement

Configured by service id (`nowo_workflow.*`) or by aliasing the interface. None of them takes a Symfony, Doctrine or
HTTP type.

| SPI | Configuration | Without it |
|-----|---------------|------------|
| `Contract\WorkflowUiAccessPolicyInterface` (1.14) | `security.access_checker` or alias | role-based policy (`security.access_roles`), deny-all fallback |
| `Contract\WorkflowTenantResolverInterface` (1.15) | `tenancy.tenant_resolver` | no tenancy |
| `Contract\WorkflowSubjectReferenceResolverInterface` (1.15) | `tenancy.subject_reference_resolver` | subjects are not pinned |
| `Contract\WorkflowActorProviderInterface` (1.15) | `tenancy.actor_provider` | security token user identifier, else none |
| `Contract\WorkflowTransitionPermissionCheckerInterface` (1.15) | `guards.permission_checker` | permission guards block (fail closed) |
| `Contract\WorkflowSubjectVariablesProviderInterface` (1.15) | `guards.variables_provider` | conditions block (fail closed) |
| `Contract\WorkflowUiTenantResolverInterface`, `WorkflowUiActionCheckerInterface` (1.15) | `tenancy.ui.*` | required with tenancy and the UI |
| `Contract\WorkflowContextAwareInterface` | on your subject | context = subject class only |

Presentation stays themable through Twig templates and the FormKit / UiKit profiles (REQ-TWIG-001); the form types and
`View\WorkflowPageFactory` are UI services, not ports.

## Events

- `Event\WorkflowTransitionAppliedEvent` (1.15): ids and scalars only (slug, definition version and tenant,
  transition, from / to places, subject class and reference, actor reference, tenant, automatic, time). Dispatched
  after the commit of the transaction that persisted the subject, never for a rollback (`Audit\AfterCommitDispatcher`
  with a DBAL middleware). Enabled with `audit.enabled` (default: on with tenancy).
- The standard Symfony `workflow.*` events of the built workflows (guard, transition, completed, …) run inside
  `apply()`, before any flush: see [USAGE.md](USAGE.md#listeners-run-before-the-flush).

## Persistence

- Definitions are read and written through the bundle's private entity manager (the only mode since 2.0) (`Doctrine\WorkflowEntityManager`),
  built on the manager that maps the bundle entities (`doctrine.entity_manager`, default: the one of a standard
  application). It is never flushed, cleared or reset by the host, and the bundle never flushes, clears or resets the
  host's managers to save a definition.
- Applying a transition changes your subject's marking. With `runtime.flush_after_apply: false` (the default since
  2.0) your repository persists the subject inside your transaction boundary; an explicit `true` flushes the default
  entity manager as 1.x did by default.
- Kept-alive workers (FrankenPHP, kernel reset off): see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). The
  new ports are the existing stateless services under an interface; they add no state.

## Standard Symfony and DDD hosts

### Standard Symfony host

Zero configuration beyond the recipe: attribute mapping on the default manager, the role-based UI policy, and
`WorkflowApplicator` / `WorkflowRuntimeInterface` from your controllers and services; flush or save your subject after
`apply*()` (`runtime.flush_after_apply: false`, the default since 2.0).

### DDD / hexagonal host

Opt-in, nothing of it is forced on standard hosts:

- map the bundle entities from the shipped XML on a dedicated manager (`doctrine.register_mapping: false` plus your
  own mapping, or `doctrine.mapping_type: xml`) and set `doctrine.entity_manager` to it;
- keep `runtime.flush_after_apply: false` (the default since 2.0);
- type-hint only the ports: `WorkflowRuntimeInterface` to move subjects, `WorkflowSlugResolverInterface` to know which
  workflow a subject runs, `WorkflowVersionManagerInterface` for tenant copies and versions;
- implement the SPI you need (access policy, tenant resolver, subject reference resolver, permission checker,
  variables provider) in your infrastructure layer;
- listen to `WorkflowTransitionAppliedEvent` (ids only, after commit) and translate it into your own events.

```php
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Contract\WorkflowSlugResolverInterface;

final readonly class SubmitLeave
{
    public function __construct(
        private WorkflowRuntimeInterface $workflows,
        private WorkflowSlugResolverInterface $slugs,
        private LeaveRepository $leaves,
    ) {
    }

    public function __invoke(LeaveId $id): void
    {
        $leave  = $this->leaves->get($id);
        $result = $this->workflows->apply($leave, $this->slugs->resolveSlugForSubject($leave), 'submit');
        if (!$result->applied) {
            throw new LeaveNotSubmittable($result->blockerCodes);
        }
        $this->leaves->save($leave); // runtime.flush_after_apply: false
    }
}
```

`tests/Integration/Ddd/DddHostKernelTest.php` and the `tenancy-ddd` kernel of `tests/Integration/Tenancy` boot this
shape.

### Replacing or decorating a port

```yaml
services:
    App\Infrastructure\Workflow\AuditedVersioning:
        decorates: Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface
        arguments: ['@.inner']
```

A decorator of `WorkflowVersionManagerInterface` sees the calls of every host service that type-hints it. The admin UI
calls the internal `Service\WorkflowVersioning` with the definition it authorised (audit NO M1), so the UI's clone,
publish, retire, reinstate and delete actions do not go through it (with 1.x a decorator of the removed
`WorkflowVersioningInterface` did). Keep the owner rule of `delete()`: a published definition is not deleted while subjects
are pinned to one of its versions (`WorkflowDefinitionInUseException`). The pin foreign key is `ON DELETE RESTRICT`,
so the database refuses such a delete even when a replacement forgets the check.
`tests/Integration/Tenancy/ArchPortsKernelTest.php` covers the aliases, a decorator, a replacement and that rule.

### Versioning without the aggregate

`Contract\WorkflowVersionManagerInterface` (since 1.19; it replaced the removed entity port `WorkflowVersioningInterface`,
rule 1) runs the versioning flows with bundle value objects only — it takes a `Model\WorkflowDefinitionRef` (row id, slug, tenant, version
number) and returns a `Model\WorkflowDefinitionView` (the scope plus the definition's own fields, with place and
transition **names** instead of Doctrine collections). `currentVersion()`, `draftVersion()` and `versions()` are the
entity-free reads that give a host its first reference.

```php
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;

final readonly class OnboardOrganization
{
    public function __construct(private WorkflowVersionManagerInterface $workflows)
    {
    }

    public function __invoke(WorkflowTenant $tenant): void
    {
        $clone = $this->workflows->cloneTemplate('leave', $tenant, publish: true);
        $this->workflows->publish($this->workflows->createDraftOf($clone->ref)->ref);
    }
}
```

`Service\WorkflowVersionManager` is the built-in implementation, a facade over the internal `Service\WorkflowVersioning`
(the same split as `WorkflowRuntimeInterface` over `WorkflowApplicator`). Decorate or replace the port to change what
your callers see. A reference is resolved on every call — scope first, then the row id — so it can never reach another tenant's version and
nothing is memoised between calls (REQ-RUNTIME-002).

## REQ-ARCH-001 status

| Rule | Status | Where |
|------|--------|-------|
| 1. Interfaces + VOs | ✅ | runtime, slug-resolver and versioning ports are framework-free (VOs in `Model\`); the entity port `WorkflowVersioningInterface` was removed in 2.0 |
| 2. No forced base class / trait | ✅ | bundle-owned entities, XML mapping (1.14), subjects referenced by class and reference |
| 3. No framework types in ports, events, VOs | ✅ | `WorkflowRegistryInterface` / `WorkflowUiAccessCheckerInterface` removed in 2.0 |
| 4. Events carry ids | ✅ | `WorkflowTransitionAppliedEvent` |
| 5. SPI documented | ✅ | this page |
| 6. Never flush the host EM | ✅ since 2.0 | `runtime.flush_after_apply` is `false` by default and definitions always go through the private entity manager; an explicit `true` is the application's own choice (the 1.x behaviour) |
| 7. Events after commit | ✅ | `AfterCommitDispatcher` |
| 8. Config through provider ports | ✅ | config tree and the SPI above; no InstanceSettings |

## Done in 2.0

What 1.x deferred to a major (see [UPGRADING.md](UPGRADING.md#unreleased)):

- **Rule 6 default.** `runtime.flush_after_apply` is `false` when unset, and the definitions are always written through
  the private entity manager (`doctrine.private_entity_manager: false` fails the build).
- **Removal of the deprecated ports.** `WorkflowRegistryInterface` and `WorkflowUiAccessCheckerInterface` are gone; a
  host service that still wires them fails the build.
- **Removal of the aggregate port.** `WorkflowVersioningInterface` is gone; `WorkflowVersionManagerInterface` is the
  only versioning port (owner decision Q2).
