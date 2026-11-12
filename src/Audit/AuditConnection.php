<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Audit;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;
use function str_starts_with;
use function strtoupper;

/**
 * Reports transactions and savepoints of a driver connection to the audit dispatcher (since 1.15).
 *
 * @internal
 */
final class AuditConnection extends AbstractConnectionMiddleware
{
    /** Savepoint statements: verb and name. */
    private const SAVEPOINT_PATTERN = '/^\s*(SAVEPOINT|SAVE\s+TRAN(?:SACTION)?|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT|ROLLBACK\s+TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    public function __construct(
        Connection $connection,
        private readonly TransactionObserverInterface $observer,
    ) {
        parent::__construct($connection);
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->observer->transactionStarted($this);
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            $this->observer->transactionEnded($this, false);

            throw $exception;
        }
        $this->observer->transactionEnded($this, true);
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->observer->transactionEnded($this, false);
        }
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_PATTERN, $sql, $match) === 1) {
            $verb = strtoupper($match[1]);
            match (true) {
                str_starts_with($verb, 'RELEASE')  => $this->observer->savepointReleased($this, $match[2]),
                str_starts_with($verb, 'ROLLBACK') => $this->observer->savepointRolledBack($this, $match[2]),
                default                            => $this->observer->savepointCreated($this, $match[2]),
            };
        }

        return $result;
    }
}
