# Usage

## Table of contents

- [Match rules and resolution](#match-rules-and-resolution)
- [Runtime API](#runtime-api)
- [Extending entities](#extending-entities)
- [Protecting the CRUD UI](#protecting-the-crud-ui)
- [Standard Symfony host](#standard-symfony-host)
- [DDD / hexagonal host](#ddd--hexagonal-host)
- [Workflow events and guards](#workflow-events-and-guards)
  - [Listeners run before the flush](#listeners-run-before-the-flush)
  - [Slugs and transition names are configuration](#slugs-and-transition-names-are-configuration)
- [Runtime port (1.15)](#runtime-port-115)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
  - [Keep the domain in charge](#keep-the-domain-in-charge)
  - [Known limitations](#known-limitations)
- [Header search: the workflow requests (SearchKit, 1.21)](#header-search-the-workflow-requests-searchkit-121)
  - [What is searched: the search text](#what-is-searched-the-search-text)
  - [The gate and the access event](#the-gate-and-the-access-event)
  - [Titles: the title resolver](#titles-the-title-resolver)
  - [Narrowing in SQL (the candidate window)](#narrowing-in-sql-the-candidate-window)
    - [Sensitive subjects: a narrower, or the side channel accepted](#sensitive-subjects-a-narrower-or-the-side-channel-accepted)
  - [What a result shows](#what-a-result-shows)
- [FrankenPHP / long-running workers](#frankenphp--long-running-workers)
- [Overriding Twig templates (REQ-TWIG-001)](#overriding-twig-templates-req-twig-001)
- [Overriding translations (REQ-I18N-001)](#overriding-translations-req-i18n-001)
- [Browser JavaScript](#browser-javascript)
- [Forms in Twig (REQ-TWIG-005)](#forms-in-twig-req-twig-005)

## Match rules and resolution

Each `WorkflowDefinition` can define zero or more `WorkflowMatchRule` rows (`parameter_key` + `parameter_value`).

- **0 rules** — default workflow for the subject class when nothing more specific matches.
- **1 rule** — e.g. `document_type=invoice`.
- **2+ rules** — all must match (AND), e.g. `tenant=acme` + `department=finance`.

Resolution order:

1. Filter enabled definitions by subject class: the subject's real class (Doctrine `__CG__` proxies are unwrapped), its parent classes and its interfaces all match, consistent with `WorkflowApplicator`'s `is_a()` check.
2. Keep definitions whose rules all match the runtime context.
3. Prefer the **closest subject class** (exact class, then parent, then interface).
4. Then pick the one with the **most rules** (highest specificity).
5. On tie, pick the highest **priority**.
6. Throw `WorkflowAmbiguousMatchException` if still tied.

Context parameter values are normalized to strings when the `WorkflowContext` is built: ints/floats are cast (`1500` → `"1500"`), booleans become `"true"` / `"false"`, `Stringable` objects are cast, `null` values are dropped; arrays and other objects throw `InvalidArgumentException`.

## Runtime API

```php
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;

// Explicit context
$definition = $resolver->resolve(new WorkflowContext(
    subjectClass: DemoExpense::class,
    parameters: ['tenant' => 'acme', 'department' => 'finance'],
));

// Subject implementing WorkflowContextAwareInterface
$applicator->applyForSubject($expense, 'approve');
$applicator->getEnabledTransitionsForSubject($expense);
$slug = $resolver->resolveSlugForSubject($expense);
```

Since 1.17 the same slug lookup is the port `Contract\WorkflowSlugResolverInterface` (`resolveSlug()`,
`resolveSlugForSubject()`), and the framework-free way to move the subject is `Contract\WorkflowRuntimeInterface`
([Runtime port](#runtime-port-115), [ARCHITECTURE.md](ARCHITECTURE.md)).

## Extending entities

- Add `metadata` JSON on `WorkflowDefinition` / `WorkflowMatchRule` for custom data.
- Subclass entities in your app (Doctrine inheritance) if you need extra columns.
- Implement `WorkflowContextAwareInterface` on domain subjects to expose lookup parameters.

## Protecting the CRUD UI

Access is **deny-by-default**: the `nowo_workflow_*` routes require one of `security.access_roles` (default `[ROLE_ADMIN]`, checked by `RoleBasedWorkflowUiAccessChecker`). Without SecurityBundle the container does not compile unless you set `security.access_checker` or `security.allow_unauthenticated: true` (dev / demo only). Also add an `access_control` rule for the UI path. See [SECURITY.md](SECURITY.md#protecting-the-crud-ui) and [CONFIGURATION.md](CONFIGURATION.md).

To enforce your own policy (roles, IP allowlist, tenant…), implement the framework-free port
`Contract\WorkflowUiAccessPolicyInterface` (since 1.14) and point `security.access_checker` at it, or alias the
interface to your service:

```php
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;

final readonly class WorkflowUiAccessPolicy implements WorkflowUiAccessPolicyInterface
{
    public function allows(WorkflowUiAccessCheck $check): bool
    {
        // $check->method, $check->routeName, $check->controller, $check->path(), $check->routeParam('id'),
        // $check->actor?->userIdentifier, $check->actor?->roles, $check->actor?->isImpersonated(), $check->actor?->user()
        return $check->actor?->hasRole('ROLE_WORKFLOW_ADMIN') === true && !$check->actor->isImpersonated();
    }
}
```

The `WorkflowUiAccessCheck` value object carries no Symfony type: the actor is a `Model\WorkflowActor` (identifier,
token role names before the role hierarchy, impersonator) built from the security token, and the path, the route
parameters and the user object are kept out of its properties (no dump, `serialize()` or `json_encode()` shows them).
Use your own authorization checker inside the policy when the role hierarchy or voters must apply. Answer `false` to
deny: the bundle answers 403, and anything else (an exception) never becomes a grant.

The 1.x `WorkflowUiAccessCheckerInterface::isGranted(Request)` was removed in 2.0 (deprecated since 1.14). A
configured service that does not implement `WorkflowUiAccessPolicyInterface` fails the build, and so does a service
registered under, aliased to or decorating the removed interface. See [UPGRADING.md](UPGRADING.md#to-200).

## Standard Symfony host

Zero configuration beyond the recipe:

- the bundle maps its entities on the default entity manager with attributes (`doctrine.mapping_type: attribute`);
  the definitions are read and written through the bundle's private entity manager on that same connection, so a
  definition save never flushes your pending changes ([CONFIGURATION.md](CONFIGURATION.md#doctrine-the-private-entity-manager));
- the CRUD UI is protected by the role-based policy (`security.access_roles`), or by your
  `WorkflowUiAccessPolicyInterface` service;
- after `WorkflowApplicator::apply*()`, flush your entity manager (`runtime.flush_after_apply: false`, the default
  since 2.0), or set `true` to let the bundle flush the default manager as 1.x did by default:

```php
$this->workflowApplicator->applyForSubject($order, 'approve');
$this->entityManager->flush();
```

## DDD / hexagonal host

Opt-in, nothing of it is forced on standard hosts:

- **Mapping**: set `doctrine.register_mapping: false` and map `Nowo\WorkflowBundle\Entity` from the shipped XML
  (`vendor/nowo-tech/workflow-bundle/src/Resources/config/doctrine`, same tables and columns as the attributes) on the
  manager you choose, e.g. a dedicated one; or keep the bundle's registration with `doctrine.mapping_type: xml`. Your
  own aggregates keep their own XML mapping and never extend, use or implement anything of the bundle: a definition
  references them by class name (`subject_class`, an interface is fine).
- **Persistence**: set `doctrine.entity_manager` to that manager. The bundle reads and writes the definitions through
  its private manager built on it (never flushing, clearing or resetting yours), and `runtime.flush_after_apply: false`
  leaves the subject to your repository and transaction boundary.
- **Access**: implement `WorkflowUiAccessPolicyInterface` (no `Request`, `TokenInterface` or `UserInterface` in the
  signature) and alias the port to it.

```yaml
# config/packages/doctrine.yaml (excerpt)
doctrine:
    orm:
        entity_managers:
            workflow:
                connection: workflow
                mappings:
                    NowoWorkflowBundle:
                        type: xml
                        dir: '%kernel.project_dir%/vendor/nowo-tech/workflow-bundle/src/Resources/config/doctrine'
                        prefix: 'Nowo\WorkflowBundle\Entity'
                        is_bundle: false

# config/packages/nowo_workflow.yaml
nowo_workflow:
    connection: workflow            # nowo:workflow:sync-schema
    runtime:
        flush_after_apply: false
    doctrine:
        entity_manager: workflow
        register_mapping: false

# config/services.yaml
services:
    Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface: '@App\Infrastructure\Workflow\WorkflowUiAccessPolicy'
```

`tests/Integration/Ddd/DddHostKernelTest.php` boots exactly this shape (a plain-PHP aggregate mapped in XML, the
bundle on a dedicated manager from the shipped XML, the host policy on the new port) and applies a transition. See
also [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application).

## Workflow events and guards

Since 1.13.0 the workflows built from the database dispatch the standard
[Symfony workflow events](https://symfony.com/doc/current/workflow.html#using-events) on the application event
dispatcher. The workflow name is the definition **slug**:

```php
use App\Domain\Expense\Approvable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;

final class ExpenseWorkflowListener
{
    public function __construct(private readonly ExpenseApprovedRecorder $recorder)
    {
    }

    // Invariant: keyed on the subject type and the target place, not on editable names (see below).
    #[AsEventListener('workflow.guard')]
    public function guard(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof Approvable || !in_array('approved', $event->getTransition()->getTos(), true)) {
            return;
        }

        if (!$subject->canBeApproved()) {
            $event->setBlocked(true, 'Budget exceeded.');
        }
    }

    #[AsEventListener('workflow.expense_approval.completed.approve')]
    public function onApproved(CompletedEvent $event): void
    {
        // Runs BEFORE the flush: only record the fact here, publish it after the commit (see below).
        $this->recorder->record($event->getSubject());
    }
}
```

A blocking guard hides the transition from `getEnabledTransitions*()` and makes `apply*()` throw
`Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException` (an `InvalidArgumentException`). Its message
includes the blocker messages (`Transition "approve" is not enabled on workflow "expense_approval": Budget exceeded.`)
and `getBlockerMessages()` / `getBlockerCodes()` / `getTransitionBlockerList()` return them. The same exception is
thrown when the current marking does not enable the transition (code `TransitionBlocker::BLOCKED_BY_MARKING`); an
unknown transition name throws a plain `InvalidArgumentException`.

`apply*()` calls Symfony's `apply()` directly, so the guard of the applied transition runs **once** per call. Guards
also run for `can()`, `getEnabledTransitions*()`, and, through the `announce` event, for every transition that leaves
the new marking: keep guard listeners free of side effects and cheap (no writes, cached look-ups).

### Listeners run before the flush

`WorkflowApplicator::apply*()` changes the marking and dispatches `leave`, `transition`, `enter`, `entered`,
`completed` and `announce` **inside** Symfony's `apply()`, then flushes (with `runtime.flush_after_apply: true`) or
leaves the flush to you (`false`, the default since 2.0). So when a `completed` or `announce` listener runs:

- the marking is already changed in memory but **not persisted yet**;
- if the flush fails, or a later listener throws, the transition is not stored, but whatever the listener already did
  (an e-mail, a message on a bus, an HTTP call) cannot be undone.

Do not send e-mails, publish messages or call other systems directly from `completed` / `announce`. Instead:

- record the domain event there and write it to a **transactional outbox** (a table written in the same flush /
  transaction as the subject), relayed by a separate process; or
- dispatch it after the **outermost transaction commits**. A Doctrine `postFlush` listener is not enough inside an
  outer transaction (it runs before that commit, and a later rollback would leave the event published). With
  Messenger, inside a message handler, use `DispatchAfterCurrentBusStamp` together with the `doctrine_transaction`
  middleware, so the stamped messages are only sent once the handler's transaction committed; or
- publish it in your application service after the transaction that saves the subject has committed (see the
  [DDD example](#using-it-from-a-ddd--hexagonal-application)), with `runtime.flush_after_apply: false`.

An exception thrown by any listener (including a global `workflow.*` listener that does not expect the subjects of the
bundle's workflows) aborts `apply*()` after the marking was changed in memory, and before the flush. The managed
subject keeps the new marking, so a later `flush()` of the same entity manager would persist the failed transition:
roll back your transaction and `refresh()` the subject (or discard it, the entity manager and any recorded events)
before continuing.

### Slugs and transition names are configuration

Slugs and transition names can be edited in the CRUD UI by anyone with access to it (`security.access_roles`). A
listener keyed on `workflow.<slug>.guard.<transition>` silently **stops applying** (fail-open) when an admin renames
the slug or the transition, or adds a second transition with the same `from` / `to` under another name. For domain
invariants and authorization checks:

- enforce the invariant in the domain (the aggregate refuses the state change), and mirror it in a `workflow.guard`
  listener that filters on the **subject** (`instanceof Approvable`) and on the **target place**
  (`$event->getTransition()->getTos()`), as above. A guard only runs for transitions: a definition whose **initial
  place** is the protected place puts a new subject there without any guard, so check the initial place of the
  definitions too;
- treat slug-specific event names as convenience hooks for reactions, not as the only line of defence;
- restrict the CRUD UI to the people allowed to change the rules.

Keep database slugs distinct from the names of the workflows configured under `framework.workflows`: both would
dispatch the same `workflow.<name>.*` events, so the framework workflow's listeners and guard expressions would receive
your database subjects. Since 1.13.0 the registry refuses such a slug while events are dispatched
(`Nowo\WorkflowBundle\Exception\WorkflowNameCollisionException` when the workflow is built): rename it in the CRUD UI.

## Runtime port (1.15)

Call `Contract\WorkflowRuntimeInterface` from your code: `can()`, `apply()`, `enabledTransitions()` and `marking()`
take your subject and a slug and return scalars or bundle VOs (`Model\WorkflowTransitionResult`,
`Model\WorkflowMarkingView`); a blocked transition is a result (`applied: false`, `blockerCodes`), not an exception.
It resolves exactly like `WorkflowApplicator` (with tenancy: the subject's pinned or tenant version, see
[MULTITENANCY.md](MULTITENANCY.md)). Replace it by aliasing the interface to your own service.

The 1.x registry port `WorkflowRegistryInterface` (it returned Symfony `WorkflowInterface` instances) was removed in
2.0 (deprecated since 1.15): call `WorkflowRuntimeInterface`, and put custom rules in a `workflow.guard` listener or in
your own `WorkflowRuntimeInterface` service. A host service that still registers, aliases or decorates the removed id
fails the build.

See demo playgrounds: 0-param orders, 1-param documents, 2-param expenses, 3-param purchase orders, plus `/playground/resolver`.

## Using it from a DDD / hexagonal application

The bundle has two parts:

- **Definitions** (places, transitions, match rules) are a small bounded context of the bundle: its own Doctrine
  entities and CRUD UI. They never reference your entities except by class name (`subject_class`).
- **The runtime** (`WorkflowRuntimeInterface`, backed by `WorkflowResolver` and `WorkflowApplicator`) builds Symfony
  workflows from those definitions and applies them to your subjects.

### Keep the domain in charge

- Implement `Contract\WorkflowContextAwareInterface` on the subject (or build a `Model\WorkflowContext` yourself) to
  pass the match parameters (`tenant`, `document_type`, …) as strings.
- Keep domain invariants in the aggregate, and mirror them in a `workflow.guard` listener filtered on the subject class
  and the target place (not on `workflow.<slug>.guard.<transition>`: slugs and transition names are editable, see
  [Slugs and transition names are configuration](#slugs-and-transition-names-are-configuration)). Guards take part in
  `can()` / `getEnabledTransitions*()`, so the UI and your code only offer transitions the domain accepts.
- Publish domain events after the commit, not from `completed` listeners (they run before the flush, see
  [Listeners run before the flush](#listeners-run-before-the-flush)).
- Keep `runtime.flush_after_apply: false` (the default since 2.0) and persist through your own repository, so the
  workflow never flushes unrelated pending changes of the entity manager outside your transaction boundary.
- Wrap the runtime in your own application service:

```php
final readonly class ApproveExpense
{
    public function __construct(
        private WorkflowApplicator $workflow,
        private ExpenseRepository $expenses,
        private DomainEventPublisher $events,
    ) {
    }

    public function __invoke(ExpenseId $id, UserId $approver): void
    {
        $expense = $this->expenses->get($id);

        $this->workflow->applyForSubject($expense, 'approve'); // guards can veto it (WorkflowTransitionBlockedException)
        $this->expenses->save($expense);                       // with runtime.flush_after_apply: false

        // Publish once the transaction that saved the expense has committed (no outer transaction is open here,
        // or the publisher is an outbox written in that same transaction).
        $this->events->publish(new ExpenseApproved($id, $approver));
    }
}
```

- Match on an interface (`subject_class: App\Domain\Expense\Approvable`) rather than a concrete class, so renaming
  classes does not orphan definitions.

### Known limitations

- The marking is read and written with `MethodMarkingStore`: the subject needs a public getter and setter for the
  configured marking property (default `status`). Use a dedicated state-holder object if your aggregate only changes
  state through intention-revealing methods.
- With an explicit `runtime.flush_after_apply: true` (the 1.x default), `apply*()` flushes the default entity manager
  after each transition (including your other pending changes).
- `WorkflowApplicator` is a final class without an interface (it works with Symfony Workflow types);
  `WorkflowRuntimeInterface` (1.15) is the framework-free, replaceable port. `WorkflowSlugResolverInterface` (1.17,
  implemented by `WorkflowResolver`) gives the slug a subject runs, and `WorkflowVersionManagerInterface` (1.19,
  implemented by `WorkflowVersionManager`) the tenant versions: it takes a `Model\WorkflowDefinitionRef` and returns a
  `Model\WorkflowDefinitionView`, never the definition entity, and adds the entity-free reads `currentVersion()`,
  `draftVersion()` and `versions()`. See [ARCHITECTURE.md](ARCHITECTURE.md).

## Header search: the workflow requests (SearchKit, 1.21)

With [SearchKit](https://github.com/nowo-tech/SearchKitBundle) 1.10 or later installed (it needs PHP 8.3 or later; it
stays optional), the bundle adds one group to the search palette and the results page **per request type** you
declare: your workflow requests (leave requests, purchase requests…), only those the viewer may see.

Each request type is a **subject**: your Doctrine ORM entity, its fields, route, gate and labels
([CONFIGURATION](CONFIGURATION.md#header-search-searchkit-121)). The subject key travels with the access event and the
title resolver, so the host always knows which entity an id belongs to.

```yaml
# config/packages/nowo_workflow.yaml
nowo_workflow:
    search_kit:
        enabled: true
        subjects:
            leave:
                group: workflow_leave_requests              # the SearchKit group key
                subject_class: App\Leave\LeaveRequest
                gate: App\Leave\LeaveSearchGate             # WorkflowRequestSearchGateInterface
                title_resolver: App\Leave\LeaveSearchTitles # WorkflowRequestTitleResolverInterface
                narrower: App\Leave\LeaveSearchNarrower     # optional, WorkflowRequestSearchNarrowerInterface
                id_field: uuid                                # in the URL, the event, the resolver, recents
                search_text_field: searchText                 # viewer-neutral text the host maintains
                status_field: status                          # a place name or a string-backed enum column
                tenant_field: organizationId                  # compared with the gate's tenant in SQL
                archived_field: deletedAt                     # not null = never searched
                order_field: updatedAt                        # newest first
                excluded_statuses: [draft]
                route: leave_request_show
                route_id_parameter: uuid
                see_all_route: leave_request_index            # filters by ?q=
                label: search.group.leave                     # group label key (label_domain: messages)
                label_domain: messages
                status_label_domain: messages
                status_label_prefix: 'leave.status.'
            purchase:                                         # the simple form: one title column
                subject_class: App\Purchase\PurchaseRequest
                gate: App\Purchase\PurchaseSearchGate
                title_field: title                            # searched and shown
                sensitive: false                              # purchase titles are not private (no narrower needed)
                status_field: place
                tenant_field: companyId
                route: purchase_show
```

One search of a subject runs, in order: the group switch (`nowo_search_kit.groups.<group>`), the gate's tenant and
feature switch, **one SQL query** (tenant, archived rows, excluded statuses and the narrowing as conditions; an
escaped `LOWER(field) LIKE … ESCAPE '!'` of every word over the search text and the status; at most `max_candidates`
rows, newest first), then **one** `WorkflowRequestAccessCheckEvent` for all candidates, SearchKit's own host event,
then **one** title resolver call for the shown page. Any refusal gives no group at all: no count, no hint.

Every subject needs a tenant: `tenant_field`, or `single_tenant: true` when every request of the class belongs to the
one tenant (an application without tenants). The container refuses a subject with neither.

### What is searched: the search text

`search_text_field` is a column **you** maintain (on every change of the request, its person or its type), e.g. the
person's display name, the leave type and the date range: `Ana García Holiday 2027-03-03 2027-03-07`. Without it,
`title_field` is searched. The status is matched too, on its **stored** value: the place name (`pending`) or the
backing value of a string-backed enum column (`LeaveStatus::Pending` → `pending`), never its translated label.

The search text must be **viewer-neutral**: nothing that only some viewers may see. Anyone the access event lets see
a request can find it by any word of its search text, so a word only managers may know (a medical leave type, a
salary band) tells whoever finds the row by it. Health and legal data never go into it: **no attachments, comments,
justification or on-behalf reason**, no approver notes, no request body. Put the viewer-dependent parts in the title
resolver instead.

### The gate and the access event

The gate gives the viewer's tenant and the feature switch, on every search (never cached):

```php
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchGateInterface;

final class LeaveSearchGate implements WorkflowRequestSearchGateInterface
{
    public function tenantOf(string $userIdentifier): int|string|null
    {
        return $this->organizations->current($userIdentifier)?->getId(); // null: no results
    }

    public function isEnabled(int|string $tenant, string $userIdentifier): bool
    {
        return $this->features->leaveEnabled($tenant) && $this->members->isMember($tenant, $userIdentifier);
    }
}
```

Whether the viewer may see each request is **your** access rule, applied in a listener of
`WorkflowRequestAccessCheckEvent`. It is **deny by default**: a request is shown only when a listener allowed it and
no listener denied it (deny wins, whatever the order; the event cannot be stopped). The event carries the subject key
and the ids and stored statuses only; load what the rule needs in **one** query for all of them:

```php
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LeaveSearchAccess
{
    public function __invoke(WorkflowRequestAccessCheckEvent $event): void
    {
        if ($event->subject !== 'leave') {
            return;
        }
        $ids = array_map(static fn ($request) => $request->id, $event->requests());
        foreach ($this->leaveVisibility->visibleIds($ids, $event->userIdentifier, $event->tenant) as $id) {
            $event->allow($id); // the same rule as the requests list
        }
    }
}
```

### Titles: the title resolver

The shown title comes from the subject's `title_resolver`, in **one** batch per results page, with exactly the ids
shown on that page (after the access event, SearchKit's host event and the page cut), and once per revalidated recent:
never with an id the viewer may not see. It gets the tenant that search decided (the gate is not asked again); a
render without a decided search shows the generic title. A title may depend on the viewer (*Absent* instead of a medical leave
type, as on your requests list). It **fails closed**: an id it leaves out shows the generic title
(`search.untitled_request`, *Request*), never the `title_field` column. Without a resolver, `title_field` is shown (the
generic title when it is empty). The bundle cuts titles to 200 characters; SearchKit escapes them.

```php
use Nowo\WorkflowBundle\Contract\WorkflowRequestTitleResolverInterface;

final class LeaveSearchTitles implements WorkflowRequestTitleResolverInterface
{
    public function titles(string $subject, array $ids, string $userIdentifier, int|string $tenant, ?string $locale): array
    {
        return $this->leaveList->titlesFor($ids, $userIdentifier, $locale); // one query, id => title
    }
}
```

The titles of a render are kept in SearchKit's per-render `SearchItemContext` (`prepareItems()` /
`toPreparedItem()`), never on the definition, so nothing reaches another viewer or request in worker mode.

### Narrowing in SQL (the candidate window)

One search reads at most `max_candidates` matching requests (newest first), then the access event decides **in PHP**.
A viewer who may see only a few requests among many (e.g. only their own) can find **none** of them when newer
requests they may not see fill the window, and a full window makes the total a lower bound. Push every rule SQL can
express into the narrower; keep the access event for what it cannot. A `narrower` adds SQL conditions for the viewer first, so their requests stay in the
window (mapped fields and bound values only; the access event still decides every row):

A leave narrower that mirrors the requests list's rules:

```php
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;

final class LeaveSearchNarrower implements WorkflowRequestSearchNarrowerInterface
{
    public function narrow(string $subject, WorkflowRequestSearchCriteria $criteria, string $userIdentifier, int|string $tenant): void
    {
        $viewer = $this->leave->viewer($tenant, $userIdentifier); // permissions in this organization, resolved per call
        if ($viewer->canManage || $viewer->canViewTeam) {
            $criteria->whereIn('organizationId', [$tenant]);      // every request of the organization
        } elseif ($viewer->canApprove) {
            // their own requests, and those of the workers they approve (their approval chain, plus workers without one)
            $criteria->whereAny(['userId' => [$viewer->userId, ...$this->approvals->workerIdsFor($viewer)]]);
        } elseif ($viewer->canRequest) {
            $criteria->whereIn('userId', [$viewer->userId]);       // only their own requests
        } else {
            $criteria->none();                                      // nothing: no group, no SQL
        }
    }
}
```

- `whereAny([...])` ORs its fields; `whereIn($field, $values)` is one field; conditions are ANDed. Scalar mapped fields
  only, values bound (never SQL text).
- `none()`: the viewer may see nothing of the subject. The search gives **no group before any SQL runs** (no
  candidates, no window places, no title lookup).
- `whereIn($field, [])`: that field matches nothing (it compiles to `1 = 0`); the query still runs and finds nothing.
- Long lists: a field's values are split into IN lists of 1,000 values, ORed, each its own bound parameter. More than
  `narrower_max_values` values in one field (default 10,000, at most 60,000; counted over every call), or more bound
  values in all the conditions together than the platform allows (60,000, under PostgreSQL's 65,535 parameters; 32,000
  on SQLite, which allows 32,766), **fails closed**: no group, no SQL
  and a logged warning (subject, total and limits; never the values). A list is never cut silently.

The narrower is asked once per search (and once per revalidated recent).

#### Sensitive subjects: a narrower, or the side channel accepted

Rows the access event hides were still read: they take places in the candidate window. A viewer can then notice them
— fewer results than expected, a total marked inexact — the **window side channel**. So a `sensitive` subject (the
default) must have a `narrower` that filters in SQL what the viewer may not see, ideally the same rule as the access
event. When only PHP or a host event can decide (the rule cannot be written as conditions), set
`accept_window_side_channel: true` on the subject to accept that side channel explicitly; the container refuses a
sensitive subject with neither.

### What a result shows

The title and the status label (`status_label_domain` / `status_label_prefix` + the stored status, else the stored
status), linking to `route`. Nothing else: never the body, comments, approver notes or any other field.

Titles of requests can be private (an absence), so every subject is `sensitive` by default: its group is off while
SearchKit keeps recents in the browser without revalidation (`nowo_search_kit.recent.revalidate: false`). With
revalidation, a stored recent is checked again: same route, an id of the id column's shape (digits within the column
type's range for a `smallint`, `integer` or `bigint` id — 32767, 2147483647, 9223372036854775807 — else letters,
digits, `_` or `-`), then the whole flow above.

In SearchKit's own host event (`nowo_search_kit.item_visibility`) the rows of a subject have the subject type
`workflow_<subject>` (e.g. `workflow_leave`), so a host listener tells the subjects apart.

The definitions are stateless (FrankenPHP worker mode with the kernel reset off): the entity manager, the tenant and
the viewer are resolved on every call; the titles of one render live in its `SearchItemContext` only.

## FrankenPHP / long-running workers

Worker mode with the kernel kept alive and **not reset** is supported (REQ-RUNTIME-002). `DatabaseWorkflowRegistry` memoizes built workflows only for the **current main request** (and implements `ResetInterface`). Since 1.14 the bundle's private entity manager (the definitions) is dropped at every outermost boundary (main request, Messenger message, outermost console command), so edits in one worker are visible to every worker on the next request, also without the services resetter. The bundle never clears **your** entity managers: a host that disables or bypasses `services_resetter` must clear them itself at every boundary (REQ-RUNTIME-002 rule 7). Details: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Overriding Twig templates (REQ-TWIG-001)

The pages follow the UiKit page contract (REQ-UI-003): theme each view type once in UiKit (`nowo_ui_kit.theme`,
`templates/bundles/NowoUiKitBundle/page/*.html.twig`) and every Workflow page follows. For a Workflow-specific need,
point `nowo_workflow.web_ui.templates.<page>` (`dashboard`, `index`, `show`, `new`, `edit`) at a thin template that
extends `@!NowoWorkflowBundle/…` and overrides a data block — see [TEMPLATES.md](TEMPLATES.md). Full copies under
`templates/bundles/NowoWorkflowBundle/` still win (REQ-TWIG-001) but must keep the data block names and the `page`
variable.

## Overriding translations (REQ-I18N-001)

The bundle ships the **`NowoWorkflowBundle`** domain in seven locales (`en`, `es`, `fr`, `it`, `de`, `nl`, `pt`) under
`src/Resources/translations/` and registers that directory as a translator path. Application catalogues in
`translations/` have priority; any key the application does not define falls back to the bundle value.

Procedure:

1. Use the bundle domain **`NowoWorkflowBundle`** (same name, same nesting of keys).
2. Create `translations/NowoWorkflowBundle.<locale>.yaml` (or `.xlf`) in the application, one file per locale you
   override.
3. Copy only the keys you want to change — look them up in the bundle catalogue
   (`vendor/nowo-tech/workflow-bundle/src/Resources/translations/NowoWorkflowBundle.en.yaml`).
4. Clear the cache: `php bin/console cache:clear`. Check with
   `php bin/console debug:translation es --domain=NowoWorkflowBundle --only-missing`.

Example — `translations/NowoWorkflowBundle.es.yaml`:

```yaml
action:
  delete: 'Eliminar definitivamente'
flash:
  deleted: 'Flujo eliminado.'
```

Every other `NowoWorkflowBundle` key (`action.edit`, `flash.created`, …) keeps the bundle translation. To add a locale
the bundle does not ship, create the full catalogue in the application and add the locale to `web_ui.locales`.

## Browser JavaScript

`nowo-workflow.js` (published by `assets:install`, loaded with `asset('js/nowo-workflow.js', 'nowo_workflow')`) is a
small dependency-free script, not a Stimulus controller: the bundle targets hosts without Symfony UX / AssetMapper, so
the pages work with plain `assets:install` (REQ-UX-001).

- Driven only by `data-*` attributes (`data-workflow-collection`, `data-collection-*`, `data-edge`); it defines **no**
  `window.*` API. The only global it reads is the vendored `window.TomSelect` constructor (transitions page).
- No `innerHTML`, `document.write`, `eval` or inline handlers: table cells are built with `textContent`, new collection
  entries are cloned from `<template data-collection-prototype>` and their `__name__` placeholders are numbered in
  attributes / text nodes.
- Initialisation is idempotent (`data-collection-ready`); the document-level listeners (diagram highlight) are
  registered once and live as long as the page. Tom Select instances are destroyed when their editor dialog closes.
- CSP notes: [SECURITY.md](SECURITY.md#browser-javascript-and-csp).

## Forms in Twig (REQ-TWIG-005)

Every UI form — bundle views and the demo apps — is a PHP Symfony Form Type; the bundle form types are FormKit Types (`#[FormKitConfig('workflow')]` + `FormOptionsTrait`, profile `workflow`, see [CONFIGURATION](CONFIGURATION.md)) and `nowo-tech/form-kit-bundle` is a required dependency whose bundle must be registered. They are rendered with `form_start`, the REQ-TWIG-003 child loop and `form_end`; submit / cancel buttons may stay outside the loop:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

CSRF-only actions (delete, logout-style buttons, toggles) use an empty Form Type too, and login forms map `_username` / `_password` / `_csrf_token` (token id `authenticate`) on a Form Type. Host overrides and demos must not reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>` markup: `make check-no-raw-html-form` (part of `make release-check`) fails when it appears.
