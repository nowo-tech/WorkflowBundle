<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

use PHPUnit\Framework\Attributes\Group;

/**
 * Since 1.21.0: the SearchKit contract of the `workflow_requests` group on MySQL 8.4 / MariaDB 11.4
 * (`NOWO_WORKFLOW_TEST_MYSQL_URL`; skipped without it; the "SearchKit databases" CI job sets it).
 */
#[Group('database')]
final class WorkflowRequestSearchMySqlContractTest extends WorkflowRequestSearchContractTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }
}
