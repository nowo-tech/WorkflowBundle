<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * REQ-RUNTIME-003 race tests on MySQL 8.4 / MariaDB 11 (`NOWO_WORKFLOW_TEST_MYSQL_URL`, e.g.
 * `pdo-mysql://root@mysql:3306/workflow`; skipped without it; the "Databases" CI job sets it).
 */
#[Group('database')]
final class ConcurrentApplyMySqlTest extends AbstractConcurrentApplyRealDatabaseTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }

    protected function shortLockWait(EntityManagerInterface $manager): void
    {
        $manager->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
    }
}
