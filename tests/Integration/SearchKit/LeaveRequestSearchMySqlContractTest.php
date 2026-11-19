<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

use PHPUnit\Framework\Attributes\Group;

/**
 * Since 1.21.0: the SearchKit contract of subject "leave" on `NOWO_WORKFLOW_TEST_MYSQL_URL` (skipped without it; the "SearchKit databases" CI job sets it).
 */
#[Group('database')]
#[Group('searchkit-mysql')]
final class LeaveRequestSearchMySqlContractTest extends WorkflowRequestSearchContractTestCase
{
    protected static function subject(): string
    {
        return 'leave';
    }

    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }
}
