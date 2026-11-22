<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Template install race tests on PostgreSQL 16 (`NOWO_WORKFLOW_TEST_PGSQL_URL`; skipped without it).
 */
#[Group('database')]
final class ConcurrentInstallPostgreSqlTest extends AbstractConcurrentInstallRealDatabaseTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_PGSQL_URL';
    }
}
