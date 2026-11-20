<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function getenv;
use function is_string;
use function sort;
use function strtolower;

use const PHP_VERSION_ID;

/**
 * 1.22.1 regression (FK loss), against a real server: with the key collation listener registered, the schema tool
 * creates every foreign key of the bundle tables, `workflow_subject_pin.definition_id` (ON DELETE RESTRICT) included,
 * and the database enforces it: a pinned definition version cannot be deleted, a definition's places go with it
 * (ON DELETE CASCADE). 1.15.0 to 1.22.0 created no foreign key on `workflow_subject_pin` on MySQL / MariaDB with
 * doctrine/orm 3.7 / 3.8 and doctrine/dbal 4.5+.
 */
abstract class SubjectPinForeignKeyTestCase extends TestCase
{
    /** Foreign keys of the bundle tables: table => list of `column -> table(column) rule`. */
    protected const FOREIGN_KEYS = [
        'workflow_match_rule'  => ['workflow_id -> workflow_definition(id) CASCADE'],
        'workflow_place'       => ['workflow_id -> workflow_definition(id) CASCADE'],
        'workflow_subject_pin' => ['definition_id -> workflow_definition(id) RESTRICT'],
        'workflow_transition'  => ['workflow_id -> workflow_definition(id) CASCADE'],
    ];

    protected EntityManagerInterface $manager;

    /** The environment variable with the server URL. */
    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $url = getenv(static::urlVariable());
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(static::urlVariable() . ' is not set.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = (new DsnParser([
            'mysql' => 'pdo_mysql', 'pdo-mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql', 'pdo-pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql',
        ]))->parse($url);
        if (($params['driver'] ?? null) === 'pdo_mysql') {
            $params['charset'] = 'utf8mb4';
        }
        $this->manager = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $this->manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, new BinaryKeyCollationSubscriber());

        $tool = new SchemaTool($this->manager);
        $tool->dropSchema($this->metadata());
        $tool->createSchema($this->metadata());
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            (new SchemaTool($this->manager))->dropSchema($this->metadata());
            $this->manager->getConnection()->close();
        }
    }

    #[Test]
    public function everyForeignKeyIsCreatedAndTheDiffIsEmpty(): void
    {
        foreach (self::FOREIGN_KEYS as $table => $expected) {
            self::assertSame($expected, $this->foreignKeys($table), $table);
        }
        self::assertSame([], (new SchemaTool($this->manager))->getUpdateSchemaSql($this->metadata()));
    }

    #[Test]
    public function aPinnedDefinitionCannotBeDeletedAndPlacesGoWithTheirDefinition(): void
    {
        // Written through the test's own entity manager (identity or sequence ids on every DBAL version).
        $connection = $this->manager->getConnection();
        $leave      = new WorkflowDefinition('Leave', 'leave', 'draft', 'X');
        $expense    = (new WorkflowDefinition('Expense', 'expense', 'draft', 'X'))->addPlace(new WorkflowPlace('draft'));
        $this->manager->persist($leave);
        $this->manager->persist($expense);
        $this->manager->persist(new WorkflowSubjectPin('acme', 'leave', 'r1', $leave));
        $this->manager->flush();
        $pinned = (int) $leave->getId();
        $free   = (int) $expense->getId();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_place'));

        $connection->delete('workflow_definition', ['id' => $free]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_place'), 'ON DELETE CASCADE');

        try {
            $connection->delete('workflow_definition', ['id' => $pinned]);
            self::fail('A pinned definition version was deleted: the RESTRICT foreign key is missing.');
        } catch (ForeignKeyConstraintViolationException) {
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_definition WHERE id = ?', [$pinned]));
    }

    /**
     * The foreign keys starting on `$table`, as the server reports them (`column -> table(column) rule`).
     *
     * @return list<string>
     */
    protected function foreignKeys(string $table): array
    {
        $rows = $this->manager->getConnection()->fetchAllAssociative(
            'SELECT kcu.column_name AS c, ccu.table_name AS t, ccu.column_name AS r, rc.delete_rule AS d'
            . ' FROM information_schema.referential_constraints rc'
            . ' JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema'
            . ' JOIN ' . $this->referencedColumns() . ' ccu ON ccu.constraint_name = rc.constraint_name AND ccu.constraint_schema = rc.constraint_schema'
            . ' WHERE kcu.table_name = ? AND kcu.table_schema = ' . $this->currentSchema(),
            [$table],
        );
        $keys = array_map(static fn (array $row): string => strtolower($row['c'] . ' -> ' . $row['t'] . '(' . $row['r'] . ') ') . $row['d'], $rows);
        sort($keys);

        return $keys;
    }

    /** SQL for the current schema. */
    abstract protected function currentSchema(): string;

    /** A relation with constraint_schema, constraint_name, table_name and column_name of the referenced columns. */
    abstract protected function referencedColumns(): string;

    /**
     * @return list<ClassMetadata<object>>
     */
    protected function metadata(): array
    {
        return $this->manager->getMetadataFactory()->getAllMetadata();
    }
}
