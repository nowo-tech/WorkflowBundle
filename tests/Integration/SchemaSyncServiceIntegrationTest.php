<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\WorkflowBundle\Service\SchemaSyncService;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SchemaSyncServiceIntegrationTest extends TestCase
{
    public function testGetCreateSchemaSqlContainsBundleTables(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createInMemory();
        $service       = new SchemaSyncService($entityManager);
        $sql           = implode("\n", $service->getCreateSchemaSql());

        self::assertStringContainsString('workflow_definition', $sql);
        self::assertStringContainsString('workflow_place', $sql);
        self::assertStringContainsString('workflow_transition', $sql);
    }

    public function testSyncSchemaSqlIsIdempotentOnEmptyDatabase(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createInMemory();
        $service       = new SchemaSyncService($entityManager);
        $connection    = $entityManager->getConnection();

        foreach ($service->getSyncSchemaSql() as $statement) {
            try {
                $connection->executeStatement($statement);
            } catch (Throwable $e) {
                if (!$service->isDuplicateSchemaObjectException($e)) {
                    throw $e;
                }
            }
        }

        self::assertSame([], $service->getCreateMissingSchemaSql());
    }

    /**
     * 1.14 → 1.15: a 1.14 definition table (no tenant_key / version / status, unique slug) is migrated by the sync, the
     * new unique index after the columns it needs; existing rows become global, version 1, published.
     */
    public function testSyncSchemaUpgradesA114DefinitionTable(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createInMemory();
        $connection    = $entityManager->getConnection();
        $connection->executeStatement('CREATE TABLE workflow_definition (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(128) NOT NULL, slug VARCHAR(128) NOT NULL, type VARCHAR(32) NOT NULL, initial_place VARCHAR(128) NOT NULL, subject_class VARCHAR(255) NOT NULL, marking_property VARCHAR(128) NOT NULL, enabled BOOLEAN NOT NULL, description CLOB DEFAULT NULL, priority INTEGER DEFAULT 0 NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_workflow_definition_slug ON workflow_definition (slug)');
        $connection->executeStatement("INSERT INTO workflow_definition (name, slug, type, initial_place, subject_class, marking_property, enabled, priority, metadata, created_at, updated_at) VALUES ('Orders', 'orders', 'state_machine', 'draft', 'App\\Order', 'status', 1, 0, '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $service = new SchemaSyncService($entityManager);

        $service->executeStatements($connection, $service->getSyncSchemaSql());

        self::assertSame([['tenant_key' => '', 'version' => 1, 'status' => 'published']], $connection->fetchAllAssociative('SELECT tenant_key, version, status FROM workflow_definition'));
        $indexes = array_keys($connection->createSchemaManager()->listTableIndexes('workflow_definition'));
        self::assertContains('uniq_workflow_definition_scope', $indexes);
        self::assertNotContains('uniq_workflow_definition_slug', $indexes);
        self::assertSame([], $service->getCreateMissingSchemaSql());
    }

    public function testGetUpdateSchemaSqlReturnsStatements(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory();
        $service       = new SchemaSyncService($entityManager);

        self::assertIsArray($service->getUpdateSchemaSql());
    }

    public function testGetCreateMissingSchemaSqlOnSyncedDatabase(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory();
        $service       = new SchemaSyncService($entityManager);

        self::assertSame([], $service->getCreateMissingSchemaSql());
    }

    public function testGetSyncSchemaSqlOnSyncedDatabaseListsExistingIndexes(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory();
        $service       = new SchemaSyncService($entityManager);

        self::assertIsArray($service->getSyncSchemaSql());
    }

    public function testCustomTablePrefixIsAppliedToSchemaSql(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createInMemory('acme_');
        $service       = new SchemaSyncService($entityManager);
        $sql           = implode("\n", $service->getCreateSchemaSql());

        self::assertStringContainsString('acme_definition', $sql);
        self::assertStringContainsString('acme_place', $sql);
        self::assertStringContainsString('uniq_acme_definition_scope', $sql);
        self::assertStringNotContainsString('CREATE TABLE workflow_', $sql);
    }

    public function testSyncSchemaSqlNeverTouchesHostTables(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory();
        $connection    = $entityManager->getConnection();
        $connection->executeStatement('CREATE TABLE host_customer (id INTEGER PRIMARY KEY, name VARCHAR(50))');
        $connection->executeStatement('CREATE INDEX idx_host_customer_name ON host_customer (name)');
        $service = new SchemaSyncService($entityManager);

        foreach ([$service->getUpdateSchemaSql(), $service->getSyncSchemaSql()] as $statements) {
            self::assertStringNotContainsStringIgnoringCase('host_customer', implode("\n", $statements));
        }

        $service->executeStatements($connection, $service->getSyncSchemaSql());

        self::assertTrue($connection->createSchemaManager()->tablesExist(['host_customer']));
    }
}
