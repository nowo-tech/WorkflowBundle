<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Doctrine\TablePrefixSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Service\SchemaSyncService;
use Throwable;

use function dirname;

final class IntegrationEntityManagerFactory
{
    public static function createInMemory(?string $tablePrefix = null): EntityManagerInterface
    {
        $entityPath = dirname(__DIR__, 2) . '/src/Entity';
        $config       = ORMSetup::createAttributeMetadataConfiguration([$entityPath], true);

        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $connection    = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $entityManager = new EntityManager($connection, $config);

        self::registerTablePrefixSubscriber($entityManager, $tablePrefix);

        return $entityManager;
    }

    public static function createSyncedInMemory(?string $tablePrefix = null): EntityManagerInterface
    {
        $entityManager = self::createInMemory($tablePrefix);
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

        return $entityManager;
    }

    private static function registerTablePrefixSubscriber(
        EntityManagerInterface $entityManager,
        ?string $tablePrefix,
    ): void {
        $prefix = $tablePrefix ?? TableNamePrefixer::DEFAULT_PREFIX;

        if ($prefix === TableNamePrefixer::DEFAULT_PREFIX) {
            return;
        }

        $entityManager->getEventManager()->addEventSubscriber(
            new TablePrefixSubscriber(new TableNamePrefixer($prefix)),
        );
        $entityManager->getClassMetadata(WorkflowDefinition::class);
    }
}
