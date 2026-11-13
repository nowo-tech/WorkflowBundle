<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function getenv;
use function is_string;

use const PHP_VERSION_ID;

/**
 * 1.15.2, against a real MySQL / MariaDB server (`NOWO_WORKFLOW_TEST_MYSQL_URL`, e.g.
 * `pdo-mysql://root:root@127.0.0.1:3306/workflow`; skipped without it; the `mysql-schema` CI job sets it):
 *
 * - after the schema is created, `doctrine:migrations:diff` / `schema:update` has nothing to do (the listener's
 *   charset + collation match what the server reports);
 * - a 1.15.1 database (`utf8mb4_bin`) gets one ALTER per key column, then is clean;
 * - keys are NO PAD and binary: `acme`, `acme ` and `Acme` are three different tenants.
 */
final class MySqlNoPadCollationTest extends TestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $url = getenv('NOWO_WORKFLOW_TEST_MYSQL_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('NOWO_WORKFLOW_TEST_MYSQL_URL is not set (MySQL / MariaDB round trip).');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params        = (new DsnParser(['mysql' => 'pdo_mysql', 'pdo-mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($url);
        $this->manager = new EntityManager(DriverManager::getConnection($params + ['charset' => 'utf8mb4'], $config), $config);
        $this->manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new BinaryKeyCollationSubscriber(new TestManagerRegistry($this->manager)));

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
    public function theDiffIsEmptyAfterTheSchemaIsCreated(): void
    {
        self::assertSame([], (new SchemaTool($this->manager))->getUpdateSchemaSql($this->metadata()));
        self::assertSame($this->expected(), $this->columnCollation('workflow_subject_pin', 'subject_ref'));
    }

    #[Test]
    public function aDatabaseOn1151IsAlteredOnceThenClean(): void
    {
        $connection = $this->manager->getConnection();
        $connection->executeStatement('ALTER TABLE workflow_definition MODIFY tenant_key VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT \'\' NOT NULL');
        self::assertSame('utf8mb4_bin', $this->columnCollation('workflow_definition', 'tenant_key'));

        $tool = new SchemaTool($this->manager);
        $sql  = $tool->getUpdateSchemaSql($this->metadata());
        self::assertCount(1, $sql);
        self::assertStringContainsString($this->expected(), $sql[0]);
        foreach ($sql as $statement) {
            $connection->executeStatement($statement);
        }
        self::assertSame([], $tool->getUpdateSchemaSql($this->metadata()));
    }

    #[Test]
    public function keysWithTrailingSpacesOrAnotherCaseAreDifferentKeys(): void
    {
        $connection = $this->manager->getConnection();
        foreach (['acme', 'acme ', 'Acme'] as $index => $tenant) {
            $connection->insert('workflow_subject_pin', ['tenant_key' => $tenant, 'workflow_slug' => 'leave', 'subject_ref' => 'r1', 'definition_id' => $this->definition($index), 'created_at' => '2026-11-12 00:00:00']);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_subject_pin WHERE tenant_key = ?', ['acme']));

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('workflow_subject_pin', ['tenant_key' => 'acme ', 'workflow_slug' => 'leave', 'subject_ref' => 'r1', 'definition_id' => $this->definition(9), 'created_at' => '2026-11-12 00:00:00']);
    }

    private function definition(int $index): int
    {
        $connection = $this->manager->getConnection();
        $definition = new WorkflowDefinition('Leave', 'leave' . $index, 'a', 'X');
        $this->manager->persist($definition);
        $this->manager->flush();
        $id = $definition->getId();
        self::assertNotNull($id);
        self::assertTrue($connection->isConnected());

        return $id;
    }

    private function expected(): string
    {
        $platform = $this->manager->getConnection()->getDatabasePlatform();
        $expected = BinaryKeyCollationSubscriber::collationFor($platform, $this->manager->getConnection()->getServerVersion());
        self::assertNotNull($expected);

        return $expected;
    }

    private function columnCollation(string $table, string $column): string
    {
        return (string) $this->manager->getConnection()->fetchOne(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function metadata(): array
    {
        return $this->manager->getMetadataFactory()->getAllMetadata();
    }
}
