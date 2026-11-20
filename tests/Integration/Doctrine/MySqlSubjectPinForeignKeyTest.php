<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

/**
 * {@see SubjectPinForeignKeyTestCase} on MySQL / MariaDB (`NOWO_WORKFLOW_TEST_MYSQL_URL`, skipped without it), plus the
 * UPGRADING.md recovery of a database created by 1.15.0 to 1.22.0 without the foreign key: after the documented
 * statements the server has the foreign key and the schema diff is empty.
 */
#[Group('database')]
final class MySqlSubjectPinForeignKeyTest extends SubjectPinForeignKeyTestCase
{
    /** The UPGRADING.md "To 1.22.1" statements (orphans re-pinned or removed, then index and foreign key). */
    public const RECOVERY = [
        "UPDATE workflow_subject_pin p LEFT JOIN workflow_definition gone ON gone.id = p.definition_id JOIN workflow_definition d ON d.id = (SELECT MAX(v.id) FROM workflow_definition v WHERE v.slug = p.workflow_slug AND v.tenant_key = p.tenant_key AND v.status = 'published') SET p.definition_id = d.id WHERE gone.id IS NULL",
        'DELETE p FROM workflow_subject_pin p LEFT JOIN workflow_definition d ON d.id = p.definition_id WHERE d.id IS NULL',
        'CREATE INDEX IDX_FBA186C2D11EA911 ON workflow_subject_pin (definition_id)',
        'ALTER TABLE workflow_subject_pin ADD CONSTRAINT FK_FBA186C2D11EA911 FOREIGN KEY (definition_id) REFERENCES workflow_definition (id) ON DELETE RESTRICT',
    ];

    protected static function urlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }

    #[Test]
    public function aDatabaseWithoutTheForeignKeyIsRecoveredByTheUpgradingStatements(): void
    {
        $connection = $this->manager->getConnection();
        $connection->executeStatement('ALTER TABLE workflow_subject_pin DROP FOREIGN KEY FK_FBA186C2D11EA911');
        $connection->executeStatement('DROP INDEX IDX_FBA186C2D11EA911 ON workflow_subject_pin');
        self::assertSame([], $this->foreignKeys('workflow_subject_pin'));
        $connection->insert('workflow_definition', [
            'name'             => 'Leave', 'slug' => 'leave', 'type' => 'workflow', 'initial_place' => 'draft', 'subject_class' => 'X',
            'marking_property' => 'status', 'enabled' => 1, 'priority' => 0, 'metadata' => '{}', 'tenant_key' => 'acme',
            'version'          => 2, 'status' => 'published', 'created_at' => '2026-11-20 00:00:00', 'updated_at' => '2026-11-20 00:00:00',
        ]);
        $current = (int) $connection->lastInsertId();
        // Two pins on deleted versions: one of a workflow with a published version (re-pinned), one of a removed workflow.
        $connection->insert('workflow_subject_pin', ['tenant_key' => 'acme', 'workflow_slug' => 'leave', 'subject_ref' => 'r1', 'definition_id' => 999998, 'created_at' => '2026-11-20 00:00:00']);
        $connection->insert('workflow_subject_pin', ['tenant_key' => 'acme', 'workflow_slug' => 'gone', 'subject_ref' => 'r1', 'definition_id' => 999999, 'created_at' => '2026-11-20 00:00:00']);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_subject_pin p LEFT JOIN workflow_definition d ON d.id = p.definition_id WHERE d.id IS NULL'));

        foreach (self::RECOVERY as $statement) {
            $connection->executeStatement($statement);
        }

        self::assertSame(self::FOREIGN_KEYS['workflow_subject_pin'], $this->foreignKeys('workflow_subject_pin'));
        self::assertSame([['workflow_slug' => 'leave', 'definition_id' => $current]], array_map(static fn (array $row): array => ['workflow_slug' => (string) $row['workflow_slug'], 'definition_id' => (int) $row['definition_id']], $connection->fetchAllAssociative('SELECT workflow_slug, definition_id FROM workflow_subject_pin')));
        self::assertSame([], (new SchemaTool($this->manager))->getUpdateSchemaSql($this->metadata()));
    }

    protected function currentSchema(): string
    {
        return 'DATABASE()';
    }

    protected function referencedColumns(): string
    {
        return '(SELECT constraint_schema, constraint_name, referenced_table_name AS table_name, referenced_column_name AS column_name FROM information_schema.key_column_usage WHERE referenced_table_name IS NOT NULL)';
    }
}
