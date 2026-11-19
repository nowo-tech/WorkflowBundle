<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

/**
 * Since 1.21.0: the SearchKit contract of subject "leave" on SQLite in memory (every PHP >= 8.3 job).
 */
final class LeaveRequestSearchSqliteContractTest extends WorkflowRequestSearchContractTestCase
{
    protected static function subject(): string
    {
        return 'leave';
    }

    protected static function urlVariable(): ?string
    {
        return null;
    }
}
