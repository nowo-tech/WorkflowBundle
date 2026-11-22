<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Concurrency;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinitionSequence;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Throwable;

use function is_numeric;

/**
 * Hands out the version numbers of a definition scope (tenant key and slug) from its counter row
 * ({@see WorkflowDefinitionSequence}), since 2.2 (REQ-RUNTIME-003 rule 4: a sequence row, never `SELECT MAX()+1`).
 *
 * {@see WorkflowDefinitionRepository::insertNextVersion()} runs two units:
 *
 * 1. {@see ensure()}, its own short transaction (a savepoint inside the caller's): when the scope has no counter row
 *    yet (its first claim since 2.2), the row is inserted with the highest version already stored, in a savepoint; the
 *    primary key decides between two first claims and the loser just goes on. That read of the stored versions is the
 *    one-time backfill of the counter, never the source of a number. The row is committed before any claim, so a claim
 *    never matches a missing row (on MySQL / MariaDB REPEATABLE READ an UPDATE that matches nothing takes a gap lock,
 *    and two first claims would then deadlock on their inserts);
 * 2. {@see claim()}, in the transaction that inserts the version: `UPDATE … SET last_version = last_version + 1 WHERE
 *    tenant_key = ? AND slug = ?` (atomic; the row lock is held until that transaction ends, so two writers of the same
 *    scope never get the same number: the second waits for the first to commit or roll back, then gets the next one),
 *    then the claimed number is read back (this transaction's own row).
 *
 * When the claim matches nothing, the counter row is committed but hidden by the caller's snapshot (PostgreSQL
 * REPEATABLE READ / SERIALIZABLE inside the caller's transaction): a {@see WorkflowConflictException} (`reason`
 * `version_claim`, 409 + `CONFLICT_001`), nothing written. A deadlock, a lock wait timeout or a serialization failure
 * is left to the transaction runner, which rolls back and answers a retryable conflict.
 *
 * The SQL comparison of the keys pre-filters as everywhere else: under a case-insensitive collation (columns created
 * before the binary key collation, or converted by hand) `Acme` and `acme` share one counter, which still never hands
 * out a number twice; only a number is read, never another scope's row.
 *
 * A value object created per call by the repository (table and column names from the mapping, prefix included): no
 * state survives a request, safe in a FrankenPHP worker with kernel reset off.
 *
 * @internal
 */
final readonly class DefinitionVersionSequence
{
    private const SAVEPOINT = 'nowo_workflow_version_seed';

    /**
     * @param string $sequenceTable quoted table of {@see WorkflowDefinitionSequence}
     * @param array{tenant: string, slug: string, last: string} $sequenceColumns quoted column names
     * @param string $definitionTable quoted table of the definitions
     * @param array{tenant: string, slug: string, version: string} $definitionColumns quoted column names
     * @param Closure(): bool $lockingRead whether reading the stored versions as last committed needs a locking read
     * @param int|null $retryAfterSeconds Retry-After hint of a conflict
     */
    public function __construct(
        private Connection $connection,
        private string $sequenceTable,
        private array $sequenceColumns,
        private string $definitionTable,
        private array $definitionColumns,
        private Closure $lockingRead,
        private ?int $retryAfterSeconds = 1,
    ) {
    }

    /**
     * Creates the scope's counter row when it has none (since 2.2 every scope gets one at its first claim), seeded with
     * the highest version stored in the scope. Must run inside a transaction (the insert runs in a savepoint of it); a
     * concurrent first claim that inserted the row first wins, and this one leaves it as it is.
     *
     * @throws LogicException when no transaction is open
     */
    public function ensure(string $slug, ?WorkflowTenant $tenant): void
    {
        $this->assertTransaction();
        $key = WorkflowTenant::storageKey($tenant);
        $s   = $this->sequenceColumns;
        if ($this->connection->fetchOne('SELECT 1 FROM ' . $this->sequenceTable . ' WHERE ' . $s['tenant'] . ' = ? AND ' . $s['slug'] . ' = ?', [$key, $slug], [ParameterType::STRING, ParameterType::STRING]) !== false) {
            return;
        }

        $highest = $this->highestStored($key, $slug, false);
        $this->connection->createSavepoint(self::SAVEPOINT);
        try {
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->sequenceTable . ' (' . $s['tenant'] . ', ' . $s['slug'] . ', ' . $s['last'] . ') VALUES (?, ?, ?)',
                [$key, $slug, $highest],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );
        } catch (UniqueConstraintViolationException $lost) {
            // A concurrent first claim inserted it (the insert waited for its commit). Audit ZH-M1: a savepoint the
            // server already dropped never replaces the original failure; the caller's transaction runner then rolls
            // back to its entry level (closing the connection if that fails too).
            try {
                $this->connection->rollbackSavepoint(self::SAVEPOINT);
            } catch (Throwable) {
                throw $lost;
            }

            return;
        }
        if ($this->connection->getDatabasePlatform()->supportsReleaseSavepoints()) {
            $this->connection->releaseSavepoint(self::SAVEPOINT);
        }
    }

    /**
     * Claims the next version number of the scope (its counter row exists: {@see ensure()}). Must run inside the
     * transaction that inserts the version: the counter row stays locked until it ends, and a rollback gives the number
     * back.
     *
     * @throws LogicException when no transaction is open
     * @throws WorkflowConflictException when the counter row is hidden by this transaction's snapshot
     */
    public function claim(string $slug, ?WorkflowTenant $tenant): int
    {
        $this->assertTransaction();
        $key = WorkflowTenant::storageKey($tenant);
        $s   = $this->sequenceColumns;
        $hit = $this->connection->executeStatement(
            'UPDATE ' . $this->sequenceTable . ' SET ' . $s['last'] . ' = ' . $s['last'] . ' + 1 WHERE ' . $s['tenant'] . ' = ? AND ' . $s['slug'] . ' = ?',
            [$key, $slug],
            [ParameterType::STRING, ParameterType::STRING],
        );
        if ($hit === 0) {
            throw new WorkflowConflictException($slug, WorkflowConflictException::REASON_VERSION_CLAIM, [], [], $this->retryAfterSeconds);
        }

        $value = $this->connection->fetchOne(
            'SELECT ' . $s['last'] . ' FROM ' . $this->sequenceTable . ' WHERE ' . $s['tenant'] . ' = ? AND ' . $s['slug'] . ' = ?',
            [$key, $slug],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return is_numeric($value) ? (int) $value : throw new LogicException('The definition version counter row vanished inside its own transaction.');
    }

    /**
     * Moves the counter up to the highest version stored in the scope (never down): a guarded atomic
     * `UPDATE … WHERE last_version < ?`. For a counter left behind by versions written without it (a host's own SQL,
     * a slug renamed into the scope). Returns whether it moved.
     */
    public function realign(string $slug, ?WorkflowTenant $tenant): bool
    {
        $key     = WorkflowTenant::storageKey($tenant);
        $highest = $this->highestStored($key, $slug, true);
        $s       = $this->sequenceColumns;

        return $this->connection->executeStatement(
            'UPDATE ' . $this->sequenceTable . ' SET ' . $s['last'] . ' = ? WHERE ' . $s['tenant'] . ' = ? AND ' . $s['slug'] . ' = ? AND ' . $s['last'] . ' < ?',
            [$highest, $key, $slug, $highest],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        ) > 0;
    }

    private function assertTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('The definition version counter is written inside the transaction that inserts the version.');
        }
    }

    /**
     * The highest version stored in the scope (0 when none): every row the SQL comparison returns counts (under a
     * case-insensitive collation a case variant too), so the number is at least the exact maximum. With $asCommitted
     * (realign) it is a locking read when the connection needs one to see the latest committed rows (MySQL / MariaDB
     * REPEATABLE READ, as {@see WorkflowDefinitionRepository::findVersionsAsCommitted()}).
     */
    private function highestStored(string $key, string $slug, bool $asCommitted): int
    {
        $d     = $this->definitionColumns;
        $value = $this->connection->fetchOne(
            'SELECT MAX(' . $d['version'] . ') FROM ' . $this->definitionTable . ' WHERE ' . $d['tenant'] . ' = ? AND ' . $d['slug'] . ' = ?'
            . ($asCommitted && ($this->lockingRead)() ? ' LOCK IN SHARE MODE' : ''),
            [$key, $slug],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return is_numeric($value) ? (int) $value : 0;
    }
}
