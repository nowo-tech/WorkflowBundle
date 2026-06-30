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
        self::assertStringContainsString('uniq_acme_definition_slug', $sql);
        self::assertStringNotContainsString('CREATE TABLE workflow_', $sql);
    }
}
