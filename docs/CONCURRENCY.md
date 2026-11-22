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
| Definition editor (admin UI: new, edit, draft, publish, clone, retire, reinstate, delete) | administrators | one flush of the bundle's private entity manager per action (a new draft: the version-number transaction below) | unique `(tenant_key, slug, version)` + catch; delete refused by the pin foreign key (`ON DELETE RESTRICT`) | 409 + `Retry-After`, `CONFLICT_001` on the bundle's routes |
| Template install (`WorkflowDefinitionCatalogInterface::installTemplate()`) | host provisioning, admin | one flush of the bundle's private entity manager (a savepoint inside the host's transaction) | unique `(tenant_key, slug, version)` + catch, winner read as last committed (since 2.1.1, inside the host's transaction: a locking read on MySQL / MariaDB REPEATABLE READ / SERIALIZABLE; a plain read in READ COMMITTED, and with MariaDB's `innodb_snapshot_isolation`) | none: the loser returns the winner with `created = false`; `LogicException` only on DBAL 3 with savepoints off (host transaction rollback-only) or a snapshot older than the winner that cannot be read past (PostgreSQL REPEATABLE READ, MariaDB `innodb_snapshot_isolation`); PostgreSQL SERIALIZABLE may raise a retryable `DeadlockException` (40001) instead |
| Version numbers (since 2.2: `WorkflowDefinitionRepository::insertNextVersion()`, used by new drafts and by a retired clone customized again) | admin, `WorkflowVersionManagerInterface`, `WorkflowDefinitionCatalogInterface::installTemplate()` | one transaction with the version's insert (transaction-doctrine runner on the definitions' connection; a savepoint inside the host's transaction); the counter row is created first in a unit of its own | per-scope counter row `workflow_definition_sequence`: atomic `UPDATE … SET last_version = last_version + 1` (row lock until the commit); the scope re-checked under the claim; unique `(tenant_key, slug, version)` key as the last guard | 409 + `Retry-After`, `CONFLICT_001` (`TransientStorageFailure`, or `WorkflowConflictException` `version_claim`); see [Definition versions](#definition-versions) |
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

With `flush_after_apply: true` (the 1.x default, an explicit choice since 2.0) and a subject of the application's default entity manager:

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

With `flush_after_apply: false` (the default since 2.0), or when the subject belongs to another entity manager than the
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

| Case | HTTP | `X-Conflict-Code` | Retry-After |
| --- | --- | --- | --- |
| Marking changed, subject deleted, newer version, deadlock / lock wait timeout / serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), nothing committed earlier in the request | 409 | `CONFLICT_001` (retry is safe) | `runtime.concurrency.retry_after` (default 1) |
| The same failures after the host (or another bundle) already committed earlier in the same request | 409 | `CONFLICT_002` (part committed: read again, do not repeat) | none |

On the bundle's own routes (the Workflow UI) `WorkflowConflictResponseSubscriber` answers these failures. Since 1.22 the
code comes from `Nowo\TransactionDoctrine\ConflictCode::forRequest($request, $failure)` (nowo-tech/transaction-doctrine
`^1.6`), so a commit made earlier in the same request is seen when the host's transaction-doctrine bundle has
`commit_detection` on (it takes the request's `CommitMark` at `kernel.request`); without a mark the failure alone
decides (always `CONFLICT_001` for the bundle's own failures: every bundle write path is one transaction, so the bundle
itself never commits part of a request). JSON requests get `{"error": "CONFLICT_00n", "message": "…"}` with 409; HTML
requests get an `HttpException(409)` rendered by the application's own error page. Both carry the header
`X-Conflict-Code` (only `CONFLICT_001` or `CONFLICT_002`) and, for `CONFLICT_001` only, `Retry-After`. The HTML answer
of `CONFLICT_001` still carries `X-Error-Code: CONFLICT_001` as before (kept for existing clients; domain codes belong in
`X-Error-Code`, the conflict code in `X-Conflict-Code`). `CONFLICT_002` answers `"Part of your change may have been
saved. Reload before trying again."`. Unique violations and lost connections are not conflicts.

## Definition versions

Since 2.2 (REQ-RUNTIME-003 rule 4, owner decision 2026-11-22) a version number is never computed as `MAX(version)+1`.
Each scope (tenant key and slug) has a counter row in `workflow_definition_sequence` (entity
`WorkflowDefinitionSequence`, written with plain DBAL statements only). `WorkflowDefinitionRepository::insertNextVersion()`
writes a new version in two units, both through nowo-tech/transaction-doctrine's `DoctrineTransactionRunner` on the
definitions' connection:

1. **The counter row exists.** When the scope has none yet (its first claim since 2.2), it is inserted with the
   highest version already stored, in a savepoint. Two first claims are decided by the primary key: the loser rolls
   back to its savepoint and goes on. This is the one-time backfill; the number itself always comes from step 2. It
   runs in its own transaction when the host has none open, so the row is committed before any claim. A claim
   therefore always updates an existing row: on MySQL / MariaDB REPEATABLE READ an `UPDATE` that matches nothing
   would take a gap lock, and two first claims would deadlock on their inserts.
2. **The claim and the insert, one transaction.** `UPDATE workflow_definition_sequence SET last_version =
   last_version + 1 WHERE tenant_key = ? AND slug = ?`, then the claimed number is read back, then the version is
   saved. The row lock is held until the commit: a concurrent writer of the same scope waits, then gets the next
   number. A rollback gives the number back.

| Case | Result |
| --- | --- |
| Two writers of one scope | the second waits for the first, then gets the next number |
| Two "new draft" requests for one definition | one draft: the second re-reads the scope under the claim and returns the first one's draft; its number is skipped (versions may have gaps) |
| A retired clone customized again by two requests | one copy: the second finds the scope no longer retired under the claim and is refused ("already has a workflow"; `installTemplate()` answers the winner with `created = false`) |
| A deleted draft or definition | its numbers are never handed out again (the counter row stays) |
| The counter behind the stored versions (versions written without it: a host's own SQL, a slug renamed into the scope, an instance still on 2.1 during a rolling deploy) | the unique `(tenant_key, slug, version)` key refuses the number; the counter is moved up to the highest stored version (a guarded `UPDATE … WHERE last_version < ?`, never down) and the write runs once more |
| Deadlock, lock wait timeout, serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03) | the runner rolls back to where it started and throws `TransientStorageFailure`: 409 + `X-Conflict-Code: CONFLICT_001` + `Retry-After` on the bundle's routes |
| The counter row committed after the snapshot of the host's REPEATABLE READ transaction (PostgreSQL) | `WorkflowConflictException`, `reason` `version_claim`: 409, `CONFLICT_001`; nothing written |

**Isolation.** A unit the bundle opens itself runs at READ COMMITTED on MySQL / MariaDB (`SET TRANSACTION ISOLATION
LEVEL READ COMMITTED`, which applies to the next transaction only; the session's level is untouched). A claim that
waited therefore reads the winner's commit; no gap lock is taken; and MariaDB's `innodb_snapshot_isolation` (on by
default since 11.6.2) cannot refuse the wait. PostgreSQL already defaults to READ COMMITTED. Inside the host's
transaction its isolation level applies. The re-check under the claim then reads the scope as last committed
(`findVersionsAsCommitted()`, the 2.1.1 locking read on MySQL / MariaDB REPEATABLE READ, unchanged). With
`innodb_snapshot_isolation` on, a claim of a counter row changed after the host's snapshot fails with ER_CHECKREAD
(1020) instead: a retryable conflict.

**Savepoints lost by the server (audit ZH-M1).** A MySQL / MariaDB deadlock (or ER_CHECKREAD) inside the host's
transaction rolls back that whole transaction and drops its savepoints. `ROLLBACK TO SAVEPOINT` then fails with 1305.
The runner rolls back to its entry level, and closes the connection when that rollback fails, so the nesting level is
never left raised (the next statement reconnects). The original transient failure is what the caller gets (a 409),
never the rollback error. The counter's own savepoint (step 1) rethrows the original unique violation when its
rollback fails. On PostgreSQL only the runner's savepoint is rolled back, and the host's transaction stays usable.

**Case-insensitive collations.** The key columns get the binary NO PAD collation like the definitions' (and are
declared to MigrationsKit). On a table converted by hand to a case-insensitive collation, `Acme` and `acme` share one
counter. That still never hands out a number twice. Only a number is read, never another scope's row.

`WorkflowDefinitionRepository::nextVersion()` (`MAX(version)+1`) is deprecated since 2.2 and no longer used by the
bundle.

## Worker mode

Everything here is stateless (FrankenPHP worker, kernel reset false): the policy is immutable, the marking guard and
the conflict subscriber hold no per-request state, and the transaction runner is created per apply. Since 2.2 the
version counter (`DefinitionVersionSequence`) and its runner are created per `insertNextVersion()` call; the
`SET TRANSACTION` level applies to that one transaction only.

## Tests and CI

- `tests/Integration/Concurrency/ConcurrentApplyTest.php` (default suite, two connections on one SQLite file): one
  transition and one conflict, a deleted subject, the `none` / `optimistic` modes, `flush_after_apply: false` inside and
  outside a host transaction, a lock held by the other connection (in the bundle's unit and in the host's), other
  failures unchanged.
- `ConcurrentApplyMySqlTest` / `ConcurrentApplyPostgreSqlTest` (group `database`, `make test-with-db`): the same race
  with real row locks, a held `FOR UPDATE` lock answered as a conflict (connection still usable, nothing committed),
  two processes racing (exactly one transition), and two writers racing for the next definition version.
- Template install (since 2.1.1, audit YP-L1): `ConcurrentInstallTest` and `ConcurrentInstallAsCommittedReadTest`
  (default suite, SQLite; the second reports a MySQL / MariaDB platform to check the locking read and its MariaDB
  `innodb_snapshot_isolation` skip), and `ConcurrentInstallMySqlTest` / `ConcurrentInstallPostgreSqlTest` (group
  `database`): two processes racing, and a loser inside the host's transaction whose snapshot predates the winner
  (answered with `created = false`, the host's transaction still usable and committed; on DBAL 3 with savepoints off,
  the documented `LogicException` and the transaction left to the host).
- Definition versions (since 2.2): `ConcurrentVersionTest` (default suite, SQLite: the counter seeded after the stored
  versions, a rolled-back write giving its number back, numbers never reused, a draft committed by another connection
  found under the claim, a counter behind the versions realigned, a collision it cannot explain rethrown, a held claim
  answered as a retryable conflict) and `ConcurrentVersionMySqlTest` / `ConcurrentVersionPostgreSqlTest` (group
  `database`, the same plus: two processes claiming 10 numbers each from a scope without a counter row, a draft race
  inside the host's transaction, a first claim committed after a REPEATABLE READ snapshot, and a real deadlock on the
  claim inside the host's transaction, audit ZH-M1: 409, connection repaired). `DefinitionVersionSequenceTest` (unit)
  covers a lost savepoint never replacing the original failure.
- The "Databases" CI job runs the apply, install and version race tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, with DBAL
  3 and 4; it is a required check on `main`.

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
- Template install inside the host's transaction (audit YS-L1, since 2.1.1): inside a REPEATABLE READ transaction the shared next-key locks also cover the index gap after the winner: other definition writes (any tenant) wait until you commit, and two such hosts can deadlock (retryable DeadlockException); keep the transaction short. The deadlock
  victim's whole host transaction is rolled back by the server. In a READ COMMITTED session the loser takes no locking
  read. On PostgreSQL SERIALIZABLE the host may instead get a retryable DeadlockException (SQLSTATE 40001, serialization failure) rather than the documented `LogicException`.
- Definition versions (since 2.2): version numbers can have gaps (a lost "new draft" race, a deleted draft); they are
  unique and increasing per scope, not dense.
- A first apply with tenancy commits the subject's version pin before the transition's unit (autocommit, outside a host
  transaction). The pin is idempotent and the first one wins, so a conflict after it still allows a safe retry.
