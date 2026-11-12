<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Audit;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware of every connection while the audit event is enabled (since 1.15, doctrine/dbal 4): tells
 * {@see AfterCommitDispatcher} when transactions begin, commit and roll back, so the audit event of a transition is
 * dispatched only once the transaction that persisted the subject committed.
 *
 * @internal
 */
final readonly class AuditMiddleware implements Middleware
{
    public function __construct(
        private TransactionObserverInterface $observer,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new AuditDriver($driver, $this->observer);
    }
}
