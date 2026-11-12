<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Audit;

/**
 * Told about the transactions of the wrapped DBAL connections ({@see AuditConnection}); the same contract as the
 * LegalKit / InstanceSettings after-commit observers.
 *
 * @internal
 */
interface TransactionObserverInterface
{
    public function transactionStarted(object $connection): void;

    public function transactionEnded(object $connection, bool $committed): void;

    public function savepointCreated(object $connection, string $name): void;

    public function savepointReleased(object $connection, string $name): void;

    public function savepointRolledBack(object $connection, string $name): void;
}
