# Concurrency

Since 1.20 the bundle meets REQ-RUNTIME-003: two requests (or workers, retries, messages) that write the same workflow
data at the same moment give correct data and a clear, retryable answer, never a double transition, a lost update or a
500.

## Table of contents

- [Write paths](#write-paths)
- [Transition applies](#transition-applies)
  - [Modes](#modes)
  - [What the lock mode does](#what-the-lock-mode-does)
  - [flush_after_apply and the host's transaction](#flush_after_apply-and-the-hosts-transaction)
  - [Optimistic locking with a version column](#optimistic-locking-with-a-version-column)
- [Conflict answers](#conflict-answers)
- [Definition versions](#definition-versions)
- [Worker mode](#worker-mode)
- [Tests and CI](#tests-and-ci)
- [Accepted residual risks](#accepted-residual-risks)

## Write paths

| Write path | Who | Transaction | Concurrency control (rule 4) | Conflict answer (rule 6) |
| --- | --- | --- | --- | --- |
| Transition apply: `WorkflowRuntimeInterface::apply()`, `WorkflowApplicator::apply*()`, `applyAutomaticTransitions()` | users (host routes, messages, commands) | `flush_after_apply: true`: one transaction on the subject's connection (`nowo-tech/transaction-doctrine` runner; a savepoint inside the host's transaction). `false`: the host's | expected-marking compare-and-set under `SELECT … FOR UPDATE` (`lock`, default), or the subject's `#[Version]` (`optimistic`) | `WorkflowConflictException` → 409 + `Retry-After`, `CONFLICT_001` |
| Subject version pin (`SubjectPinStore`, tenancy) | first apply of a subject | own insert, in a savepoint inside a host transaction | unique `(tenant_key, workflow_slug, subject_ref)` + catch, winner read with a locking read | `WorkflowPinConflictException` → 409, `CONFLICT_001` |
| Definition editor (admin UI: new, edit, draft, publish, clone, retire, reinstate, delete) | administrators | one flush of the bundle's private entity manager per action | unique `(tenant_key, slug, version)` + catch; delete refused by the pin foreign key (`ON DELETE RESTRICT`) | 409 + `Retry-After`, `CONFLICT_001` on the bundle's routes |
| Version numbers (`WorkflowDefinitionRepository::nextVersion()`) | admin | inside the definition save | `MAX(version)+1`, decided by the unique `(tenant_key, slug, version)` key + catch | see [Definition versions](#definition-versions) |
| Audit events (`WorkflowTransitionAppliedEvent`) | after a commit | none (dispatched after the commit, dropped on rollback) | — | — |
| `nowo:workflow:sync-schema`, `nowo:workflow:seed-demo` | operators, demo | DDL / one flush | single operator | — |

## Transition applies

The marking lives on the host's subject entity. Before 1.20 `apply()` ran the guards on the marking the request had
loaded, changed it and flushed: two concurrent applies from the same place both passed the guards and both committed
(a double transition, last write wins).

### Modes

Per workflow, under `nowo_workflow.runtime.concurrency`:

```yaml
nowo_workflow:
    runtime:
        concurrency:
            default: lock            # lock | optimistic | none
            workflows:
                leave_request: optimistic
                imported_orders: none
            retry_after: 1           # seconds, Retry-After of a conflict
```

| Mode | Control | Use it when |
| --- | --- | --- |
| `lock` (default) | the subject's marking column is re-read with `SELECT … FOR UPDATE` and must equal the marking the request loaded | always safe; any Doctrine entity subject |
| `optimistic` | the marking is compared without a lock, the versioned `UPDATE … WHERE version = ?` decides at the flush | the subject has a `#[Version]` field and you want no row lock (refused with a `LogicException` otherwise) |
| `none` | no control (the 1.19 behaviour) | a workflow written by a single writer only |

The control applies to a subject that is a persisted Doctrine entity (managed by its class's entity manager, or the
application's default one) whose marking property is a mapped field. A new subject not inserted yet has no row to race
on; a non-Doctrine subject, or a marking property that is not a mapped column, is applied without control: protect it
in your own write unit.

### What the lock mode does

With `flush_after_apply: true` (the 1.13 default) and a subject of the application's default entity manager:

1. A transaction opens on the subject's connection (transaction-doctrine's `DoctrineTransactionRunner::forConnection()`:
   a savepoint when the host already has a transaction open).
2. `SELECT <marking> FROM <table that declares the marking column> WHERE <id columns> = ? FOR UPDATE` (plain SQL:
   the identity map and the subject object are not touched; with JOINED inheritance the subclass tables are never
   joined, which PostgreSQL could not lock). The value is converted with the field's Doctrine type (a JSON marking of
   a `workflow` / Petri net compares as its place => tokens map: key order and token counts matter as Symfony sees
   them) and must equal the marking in the unit of work's snapshot of the subject, i.e. what this request loaded (a
   proxy subject is initialised first). A concurrent apply that committed first, or a deleted row, refuses the apply with
   `WorkflowConflictException` (`reason` `marking_changed` / `subject_missing`): nothing is applied.
3. The transition (guards, Symfony workflow events), the automatic transitions it enables, and the flush.
4. Commit; then the audit events go out.

A concurrent apply waits at step 2 until the winner commits, then sees the new marking and gets the conflict. A
deadlock, a lock wait timeout or a serialization failure at any step rolls back to where the unit started, leaves the
connection usable, and is a `WorkflowConflictException` with `reason` `lock`.

The application's entity manager is never cleared or reset by the bundle. A manager that a failed flush closed (the
ORM closes it) is replaced through the registry, as before.

### flush_after_apply and the host's transaction

With `flush_after_apply: false` (the recipe value), or when the subject belongs to another entity manager than the
default one, the host owns the unit of work and the bundle flushes nothing:

- with a transaction open on the subject's connection, the marking is read with `SELECT … FOR UPDATE` in a savepoint
  of the bundle's own: the row lock is held until the host commits, so the apply is safe, and a failed read (a conflict
  or a lock wait timeout) rolls back to that savepoint only, so the host's transaction stays usable and keeps its
  earlier writes (on PostgreSQL an error would otherwise abort the whole transaction);
- without one, the marking is compared without a lock: a stale subject is refused, but the window between the check
  and your flush stays open. Wrap the apply and your flush in one transaction (`EntityManager::wrapInTransaction()`,
  or your `TransactionRunnerInterface`) to close it, or give the subject a `#[Version]` field.

### Optimistic locking with a version column

A subject with a Doctrine `#[Version]` field is protected by Doctrine itself whatever the mode: a versioned UPDATE that
finds a newer version throws `OptimisticLockException`, which the bundle turns into a `WorkflowConflictException`
(`reason` `version_changed`) when it flushes, and which the bundle's routes answer 409. With `optimistic` the row lock
of step 2 is skipped and the version decides.

## Conflict answers

`WorkflowConflictException` (and `WorkflowPinConflictException`) mean "nothing of the apply was committed; reload the
subject and retry". `WorkflowConflictException` extends `Nowo\TransactionContract\TransientStorageFailure` (with a
`retryAfterSeconds()` hint) and implements `Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface`, so a
host with transaction-doctrine's `ConcurrencyConflictResponder` answers it on its own routes with no mapping. Its
message is constant and client-safe; `reason`, `workflowSlug`, `expectedPlaces` and `currentPlaces` are public.

| Case | HTTP | Code | Retry-After |
| --- | --- | --- | --- |
| Marking changed, subject deleted, newer version, deadlock / lock wait timeout / serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03) | 409 | `CONFLICT_001` (retry is safe) | `runtime.concurrency.retry_after` (default 1) |

On the bundle's own routes (the Workflow UI) `WorkflowConflictResponseSubscriber` answers these failures: JSON
requests get `{"error": "CONFLICT_001", "message": "…"}` with 409 and `Retry-After`; HTML requests get an
`HttpException(409)` with `Retry-After` and `X-Error-Code: CONFLICT_001`, rendered by the application's own error page.
`CONFLICT_002` (partly committed) does not occur: every bundle write path is one transaction. Unique violations and
lost connections are not conflicts.

## Definition versions

`nextVersion()` is `MAX(version)+1` (REQ-RUNTIME-003 rule 4 prefers a sequence row): two concurrent writers can compute
the same number, but the unique `(tenant_key, slug, version)` key accepts only one. `WorkflowVersioning::createDraftOf()`
catches the violation and returns the winner's draft; `cloneTemplate()` refuses with "already has a workflow". This is
an admin-only path with one writer at a time in practice: accepted.

## Worker mode

Everything here is stateless (FrankenPHP worker, kernel reset false): the policy is immutable, the marking guard and
the conflict subscriber hold no per-request state, and the transaction runner is created per apply.

## Tests and CI

- `tests/Integration/Concurrency/ConcurrentApplyTest.php` (default suite, two connections on one SQLite file): one
  transition and one conflict, a deleted subject, the `none` / `optimistic` modes, `flush_after_apply: false` inside and
  outside a host transaction, a lock held by the other connection (in the bundle's unit and in the host's), other
  failures unchanged.
- `ConcurrentApplyMySqlTest` / `ConcurrentApplyPostgreSqlTest` (group `database`, `make test-with-db`): the same race
  with real row locks, a held `FOR UPDATE` lock answered as a conflict (connection still usable, nothing committed),
  two processes racing (exactly one transition), and two writers racing for the next definition version.
- The "Databases" CI job runs them on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16; it is a required check on `main`.

## Accepted residual risks

- `flush_after_apply: false` without a transaction: checked without a lock (see above).
- Non-Doctrine subjects and unmapped marking properties: no control; the host's write unit decides.
- Symfony workflow listeners run inside the apply's transaction: a listener doing HTTP, mail or file I/O holds the row
  lock meanwhile (REQ-RUNTIME-003 rule 9). Do such work after the commit (`WorkflowTransitionAppliedEvent`).
- A host listener that flushes during the apply (an `entered` listener, say) writes inside the bundle's transaction;
  when a later listener or the flush then fails, the transaction rolls back, but the host's entity manager is not
  cleared (the bundle never clears it): its snapshot still holds the rolled-back values, a later host flush writes
  nothing for them and the next apply on that subject is a conflict. Reload the subject after a failed apply, and do
  not flush from workflow listeners.
- Listeners that write other rows while the subject's row lock is held can deadlock with a request that locks the
  same rows in the other order. The database picks a loser, which gets `WorkflowConflictException` (`reason` `lock`,
  connection usable, nothing of it committed); the data stays consistent. Keep one lock order (the subject first, then
  the rows a listener writes), or move those writes after the commit.
- A first apply with tenancy commits the subject's version pin before the transition's unit (autocommit, outside a host
  transaction). The pin is idempotent and the first one wins, so a conflict after it still allows a safe retry.
