<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\ServerVersionProvider;
use SensitiveParameter;

use function str_replace;

/**
 * Lets a SQLite file stand in for a server with row locks in the default suite (DBAL 4): the driver reports a MySQL
 * platform (so the bundle writes `SELECT … FOR UPDATE`), and this middleware records each statement and drops the
 * `FOR UPDATE` that SQLite cannot parse.
 */
final class LockingSqlMiddleware implements Middleware
{
    /** @var list<string> */
    public array $statements = [];

    public function wrap(Driver $driver): Driver
    {
        $log = $this;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly LockingSqlMiddleware $log)
            {
                parent::__construct($driver);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): MySQL80Platform
            {
                return new MySQL80Platform();
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $log = $this->log;

                return new class(parent::connect($params), $log) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly LockingSqlMiddleware $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare($this->log->strip($sql));
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query($this->log->strip($sql));
                    }
                };
            }
        };
    }

    public function strip(string $sql): string
    {
        $this->statements[] = $sql;

        return str_replace(' FOR UPDATE', '', $sql);
    }
}
