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
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\ServerVersionProvider;
use SensitiveParameter;

use function str_contains;
use function str_replace;

/**
 * Lets a SQLite file stand in for a server with row locks in the default suite (DBAL 4): the driver reports a MySQL
 * platform (so the bundle writes `SELECT … FOR UPDATE`), and this middleware records each statement and drops the
 * `FOR UPDATE` that SQLite cannot parse.
 *
 * Since 2.1.1 (audit YP-L1) it also drops `LOCK IN SHARE MODE`, can report another MySQL-family platform (MariaDB), and
 * answers a statement containing one of the $answers keys with that key's replacement SQL (a server variable read).
 */
final class LockingSqlMiddleware implements Middleware
{
    /** @var list<string> */
    public array $statements = [];

    /**
     * @param array<string, string> $answers SQL fragment => the SQLite statement that runs instead
     */
    public function __construct(
        private readonly AbstractMySQLPlatform $platform = new MySQL80Platform(),
        private readonly array $answers = [],
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $log = $this;

        return new class($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly LockingSqlMiddleware $log)
            {
                parent::__construct($driver);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractMySQLPlatform
            {
                return $this->log->platform();
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

                    public function exec(string $sql): int|string
                    {
                        return parent::exec($this->log->strip($sql));
                    }
                };
            }
        };
    }

    public function platform(): AbstractMySQLPlatform
    {
        return $this->platform;
    }

    public function strip(string $sql): string
    {
        $this->statements[] = $sql;
        foreach ($this->answers as $fragment => $answer) {
            if (str_contains($sql, $fragment)) {
                return $answer;
            }
        }

        return str_replace([' FOR UPDATE', ' LOCK IN SHARE MODE'], '', $sql);
    }
}
