<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Audit;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @internal
 */
final class AuditDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TransactionObserverInterface $observer,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        return new AuditConnection(parent::connect($params), $this->observer);
    }
}
