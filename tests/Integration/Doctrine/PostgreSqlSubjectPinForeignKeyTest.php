<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use PHPUnit\Framework\Attributes\Group;

/**
 * {@see SubjectPinForeignKeyTestCase} on PostgreSQL (`NOWO_WORKFLOW_TEST_PGSQL_URL`, skipped without it): the listener
 * does nothing there, the foreign keys are created and enforced.
 */
#[Group('database')]
final class PostgreSqlSubjectPinForeignKeyTest extends SubjectPinForeignKeyTestCase
{
    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_PGSQL_URL';
    }

    protected function currentSchema(): string
    {
        return 'current_schema()';
    }

    protected function referencedColumns(): string
    {
        return 'information_schema.constraint_column_usage';
    }
}
