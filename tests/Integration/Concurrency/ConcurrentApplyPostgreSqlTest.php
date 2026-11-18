<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * REQ-RUNTIME-003 race tests on PostgreSQL 16 (`NOWO_WORKFLOW_TEST_PGSQL_URL`, e.g.
 * `pdo-pgsql://postgres@postgres:5432/workflow`; skipped without it; the "Databases" CI job sets it).
 */
#[Group('database')]
final class ConcurrentApplyPostgreSqlTest extends AbstractConcurrentApplyRealDatabaseTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_PGSQL_URL';
    }

    protected function shortLockWait(EntityManagerInterface $manager): void
    {
        $manager->getConnection()->executeStatement("SET lock_timeout = '1s'");
    }
}
