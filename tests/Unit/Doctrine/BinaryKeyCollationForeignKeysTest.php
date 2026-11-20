<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function ksort;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_starts_with;

use const PHP_VERSION_ID;

/**
 * 1.22.1 regression (FK loss): the schema generated with the key collation listener has exactly the foreign keys and
 * indexes of the schema generated without it, on every table. 1.15.0 to 1.22.0 replaced the table through the DBAL 4.5+
 * editor and doctrine/orm 3.7 / 3.8 then added the deferred foreign key to the old table: on MySQL / MariaDB
 * `workflow_subject_pin` had no `definition_id` foreign key (ON DELETE RESTRICT) and no index on it.
 *
 * No server is needed: the platform comes from the configured server version.
 */
final class BinaryKeyCollationForeignKeysTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function servers(): iterable
    {
        yield 'MySQL 8.4' => ['8.4.3', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.0.16' => ['8.0.16', 'utf8mb4_bin'];
        yield 'MariaDB 11.4' => ['11.4.4-MariaDB', 'utf8mb4_nopad_bin'];
        yield 'MariaDB 10.11' => ['10.11.9-MariaDB', 'utf8mb4_nopad_bin'];
    }

    #[Test]
    #[DataProvider('servers')]
    public function theListenerKeepsEveryForeignKeyAndIndex(string $serverVersion, string $collation): void
    {
        $without = $this->keys($this->sql($serverVersion, false));
        $with    = $this->sql($serverVersion, true);

        self::assertSame($without, $this->keys($with));
        self::assertContains('ALTER TABLE workflow_subject_pin ADD CONSTRAINT FK_FBA186C2D11EA911 FOREIGN KEY (definition_id) REFERENCES workflow_definition (id) ON DELETE RESTRICT', $this->keys($with)['workflow_subject_pin']);
        self::assertContains('INDEX IDX_FBA186C2D11EA911 (definition_id)', $this->keys($with)['workflow_subject_pin']);
        foreach (['tenant_key', 'workflow_slug', 'subject_ref'] as $column) {
            self::assertStringContainsString($column . ' VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `' . $collation . '`', $this->create($with, 'workflow_subject_pin'), $column);
        }
        self::assertStringContainsString('slug VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `' . $collation . '`', $this->create($with, 'workflow_definition'));
    }

    /**
     * @return list<string>
     */
    private function sql(string $serverVersion, bool $listener): array
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion], $config);
        $manager    = new EntityManager($connection, $config);
        if ($listener) {
            $manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, new BinaryKeyCollationSubscriber());
        }

        return (new SchemaTool($manager))->getCreateSchemaSql($manager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param list<string> $sql
     */
    private function create(array $sql, string $table): string
    {
        foreach ($sql as $statement) {
            if (str_starts_with($statement, 'CREATE TABLE ' . $table . ' (')) {
                return $statement;
            }
        }
        self::fail('No CREATE TABLE ' . $table);
    }

    /**
     * The foreign keys (`ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY`) and the indexes of each table, from the schema
     * tool's own SQL (no DBAL API that differs between DBAL 3 and 4).
     *
     * @param list<string> $sql
     *
     * @return array<string, list<string>>
     */
    private function keys(array $sql): array
    {
        $keys = [];
        foreach ($sql as $statement) {
            if (preg_match('/^CREATE TABLE (\w+) \(/', $statement, $table) === 1) {
                $keys[$table[1]] ??= [];
                preg_match_all('/(?:UNIQUE )?INDEX \w+ \([^)]*\)/', $statement, $indexes);
                foreach ($indexes[0] as $index) {
                    $keys[$table[1]][] = $index;
                }
            } elseif (preg_match('/^ALTER TABLE (\w+) ADD CONSTRAINT \w+ FOREIGN KEY/', $statement, $table) === 1) {
                $keys[$table[1]][] = $statement;
            }
        }
        foreach ($keys as &$list) {
            sort($list);
        }
        unset($list);
        ksort($keys);

        return $keys;
    }
}
