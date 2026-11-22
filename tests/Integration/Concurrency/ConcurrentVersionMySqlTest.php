<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use PHPUnit\Framework\Attributes\Group;

/**
 * Definition version race tests on MySQL 8.4 / MariaDB 11 (`NOWO_WORKFLOW_TEST_MYSQL_URL`; skipped without it), since 2.2.
 */
#[Group('database')]
final class ConcurrentVersionMySqlTest extends AbstractConcurrentVersionRealDatabaseTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }
}
