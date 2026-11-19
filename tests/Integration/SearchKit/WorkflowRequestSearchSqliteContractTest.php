<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

/**
 * Since 1.21.0: the SearchKit contract of the `workflow_requests` group on SQLite (in memory, every PHP >= 8.3 job).
 */
final class WorkflowRequestSearchSqliteContractTest extends WorkflowRequestSearchContractTestCase
{
    protected static function urlVariable(): ?string
    {
        return null;
    }
}
