<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Exception;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Doctrine\TablePrefixSubscriber;
use Nowo\WorkflowBundle\Doctrine\UnprefixedTablesGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

use const PHP_VERSION_ID;

/**
 * 1.15.2: a custom table_prefix that meets the unprefixed tables of a DoctrineBundle 3 host (1.15.0 / 1.15.1) stops
 * schema generation with the RENAME statements, instead of a diff that drops the tables.
 */
final class UnprefixedTablesGuardTest extends TestCase
{
    #[Test]
    public function unprefixedTablesStopSchemaGenerationWithTheRenameStatements(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The 1.15.1 tables of a DoctrineBundle 3 host: default names.
        (new SchemaTool($this->manager($connection, TableNamePrefixer::DEFAULT_PREFIX)))->createSchema($this->manager($connection, TableNamePrefixer::DEFAULT_PREFIX)->getMetadataFactory()->getAllMetadata());

        $prefixed = $this->manager($connection, 'acme_');
        try {
            (new SchemaTool($prefixed))->getUpdateSchemaSql($prefixed->getMetadataFactory()->getAllMetadata());
            self::fail('The diff would drop the tables.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('nowo_workflow.table_prefix is "acme_"', $exception->getMessage());
            self::assertStringContainsString("ALTER TABLE workflow_definition RENAME TO acme_definition;\nALTER TABLE workflow_place RENAME TO acme_place;", $exception->getMessage());
            self::assertStringContainsString('ALTER TABLE workflow_subject_pin RENAME TO acme_subject_pin;', $exception->getMessage());
        }

        // Renamed: the guard is quiet.
        foreach (UnprefixedTablesGuard::DEFAULT_TABLES as $table) {
            $connection->executeStatement('ALTER TABLE ' . $table . ' RENAME TO ' . (new TableNamePrefixer('acme_'))->applyPrefix($table));
        }
        (new SchemaTool($prefixed))->getUpdateSchemaSql($prefixed->getMetadataFactory()->getAllMetadata());
        self::addToAssertionCount(1);
    }

    #[Test]
    public function mysqlGetsRenameTableAndNothingHappensWithoutTablesOrPrefixOrDatabase(): void
    {
        $em     = $this->createMock(EntityManagerInterface::class);
        $schema = new Schema();
        $schema->createTable('acme_definition');
        $mysql = $this->createMock(Connection::class);
        $mysql->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $em->method('getConnection')->willReturn($mysql);

        // Default prefix and an empty schema: nothing to compare.
        (new UnprefixedTablesGuard(new TableNamePrefixer()))->postGenerateSchema(new GenerateSchemaEventArgs($em, $schema));
        (new UnprefixedTablesGuard(new TableNamePrefixer('acme_')))->postGenerateSchema(new GenerateSchemaEventArgs($em, new Schema()));

        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sqlite->executeStatement('CREATE TABLE workflow_definition (id INTEGER)');
        $withTable = $this->createMock(Connection::class);
        $withTable->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $withTable->method('createSchemaManager')->willReturn($sqlite->createSchemaManager());
        $mysqlEm = $this->createMock(EntityManagerInterface::class);
        $mysqlEm->method('getConnection')->willReturn($withTable);

        // No database to read (offline SQL generation): nothing to compare.
        $offline = $this->createMock(Connection::class);
        $offline->method('createSchemaManager')->willThrowException(new ConnectionException(new class('down') extends Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        }, null));
        $offlineEm = $this->createMock(EntityManagerInterface::class);
        $offlineEm->method('getConnection')->willReturn($offline);
        (new UnprefixedTablesGuard(new TableNamePrefixer('acme_')))->postGenerateSchema(new GenerateSchemaEventArgs($offlineEm, $schema));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RENAME TABLE workflow_definition TO acme_definition;');
        (new UnprefixedTablesGuard(new TableNamePrefixer('acme_')))->postGenerateSchema(new GenerateSchemaEventArgs($mysqlEm, $schema));
    }

    #[Test]
    public function bothTheDefaultAndThePrefixedTablesStopSchemaGeneration(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sqlite->executeStatement('CREATE TABLE workflow_definition (id INTEGER)');
        $sqlite->executeStatement('CREATE TABLE acme_definition (id INTEGER)');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($sqlite);
        $schema = new Schema();
        $schema->createTable('acme_definition');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('both the default and the prefixed Workflow tables exist: workflow_definition / acme_definition');
        (new UnprefixedTablesGuard(new TableNamePrefixer('acme_')))->postGenerateSchema(new GenerateSchemaEventArgs($em, $schema));
    }

    private function manager(Connection $connection, string $prefix): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $manager  = new EntityManager($connection, $config);
        $prefixer = new TableNamePrefixer($prefix);
        $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber($prefixer));
        $manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, new UnprefixedTablesGuard($prefixer));

        return $manager;
    }
}
