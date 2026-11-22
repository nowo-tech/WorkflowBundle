<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Template install race tests on MySQL 8.4 / MariaDB 11 (`NOWO_WORKFLOW_TEST_MYSQL_URL`; skipped without it).
 */
#[Group('database')]
final class ConcurrentInstallMySqlTest extends AbstractConcurrentInstallRealDatabaseTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }
}
