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
- [REQ-ARCH-001 status](#req-arch-001-status)
- [Deferred to 2.0](#deferred-to-20)

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
| `Contract\WorkflowSlugResolverInterface` | `Service\WorkflowResolver` | 1.17 | `resolveSlug(WorkflowContext)`, `resolveSlugForSubject(object)`: the slug to pass to the runtime port. |
| `Contract\WorkflowVersioningInterface` | `Service\WorkflowVersioning` | 1.17 | Drafts, publishing, retiring, template clones, `forgetSubject()`, `delete()`. The admin UI calls it too. |

`Service\WorkflowApplicator` and `Service\WorkflowResolver`'s entity-returning methods stay public for standard hosts
(final classes, no interface): the applicator works with Symfony Workflow types, so the runtime port is its
framework-free replacement. `Contract\WorkflowRegistryInterface` (returns Symfony `WorkflowInterface`) is deprecated as a
port for hosts since 1.15.

## SPI hosts implement

Configured by service id (`nowo_workflow.*`) or by aliasing the interface. None of them takes a Symfony, Doctrine or
HTTP type, except the deprecated ones.

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
| `Contract\WorkflowUiAccessCheckerInterface` | deprecated since 1.14 (takes `Request`) | — |
| `Contract\WorkflowRegistryInterface` | deprecated port since 1.15, still the SPI of `WorkflowApplicator` without tenancy | — |

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

- Definitions are read and written through the bundle's private entity manager (`Doctrine\WorkflowEntityManager`),
  built on the manager that maps the bundle entities (`doctrine.entity_manager`, default: the one of a standard
  application). It is never flushed, cleared or reset by the host, and the bundle never flushes, clears or resets the
  host's managers to save a definition.
- Applying a transition changes your subject's marking. With `runtime.flush_after_apply: false` (the recipe value
  since 1.14) your repository persists the subject inside your transaction boundary; `true` (the value when the key
  is not set, deprecated) flushes the default entity manager as in 1.13.
- Kept-alive workers (FrankenPHP, kernel reset off): see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). The
  new ports are the existing stateless services under an interface; they add no state.

## Standard Symfony and DDD hosts

### Standard Symfony host

Zero configuration beyond the recipe: attribute mapping on the default manager, the role-based UI policy, and
`WorkflowApplicator` / `WorkflowRuntimeInterface` from your controllers and services. Nothing of 1.17 needs a change:
the new interfaces are aliases of the services you already autowire by class.

### DDD / hexagonal host

Opt-in, nothing of it is forced on standard hosts:

- map the bundle entities from the shipped XML on a dedicated manager (`doctrine.register_mapping: false` plus your
  own mapping, or `doctrine.mapping_type: xml`) and set `doctrine.entity_manager` to it;
- set `runtime.flush_after_apply: false`;
- type-hint only the ports: `WorkflowRuntimeInterface` to move subjects, `WorkflowSlugResolverInterface` to know which
  workflow a subject runs, `WorkflowVersioningInterface` for tenant copies and versions;
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
        decorates: Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface
        arguments: ['@.inner']
```

The admin UI type-hints `WorkflowVersioningInterface`, so a decorator also sees the UI's clone, publish, retire,
reinstate and delete actions. Keep the owner rule of `delete()`: a published definition is not deleted while subjects
are pinned to one of its versions (`WorkflowDefinitionInUseException`). The pin foreign key is `ON DELETE RESTRICT`,
so the database refuses such a delete even when a replacement forgets the check.
`tests/Integration/Tenancy/ArchPortsKernelTest.php` covers the aliases, a decorator, a replacement and that rule.

## REQ-ARCH-001 status

| Rule | Status | Where |
|------|--------|-------|
| 1. Interfaces + VOs | ✅ since 1.17 | runtime, slug resolver and versioning ports; VOs in `Model\` |
| 2. No forced base class / trait | ✅ | bundle-owned entities, XML mapping (1.14), subjects referenced by class and reference |
| 3. No framework types in ports, events, VOs | ✅ since 1.15 | deprecated `WorkflowRegistryInterface` / `WorkflowUiAccessCheckerInterface` kept for BC until 2.0 |
| 4. Events carry ids | ✅ | `WorkflowTransitionAppliedEvent` |
| 5. SPI documented | ✅ | this page |
| 6. Never flush the host EM | ⚠️ default until 2.0 | `runtime.flush_after_apply` is `true` when unset (deprecated since 1.14); `false` in the recipe and in 2.0 |
| 7. Events after commit | ✅ | `AfterCommitDispatcher` |
| 8. Config through provider ports | ✅ | config tree and the SPI above; no InstanceSettings |

## Deferred to 2.0

These need a major release (they change a default or remove a public API); they are tracked in
[UPGRADING.md](UPGRADING.md#to-1170):

- **Rule 6 default.** `runtime.flush_after_apply` becomes `false` when unset. Changing it in 1.x would silently stop
  persisting the marking of every host that relies on the 1.13 flush, so 1.x only deprecates the unset key (since
  1.14) and the recipe sets `false`.
- **Removal of the deprecated ports.** `WorkflowRegistryInterface` as a host port and
  `WorkflowUiAccessCheckerInterface::isGranted(Request)` go away.
- **Versioning by id.** `WorkflowVersioningInterface` takes the bundle's own `WorkflowDefinition` aggregate for the
  actions on an already authorised version (`publish()`, `retire()`, `reinstate()`, `delete()`, …). It is a
  bundle-owned entity, not a host entity or a framework type; id-based signatures would change the interface, so they
  wait for 2.0.
