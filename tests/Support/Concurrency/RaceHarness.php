<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Concurrency\WorkflowConcurrencyPolicy;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use PDO;

use const PHP_VERSION_ID;

/**
 * Two (or more) entity managers on one database, each with its own connection, for the REQ-RUNTIME-003 race tests:
 * a SQLite file (default suite) or a real server (`NOWO_WORKFLOW_TEST_*_URL`, group `database`).
 */
final class RaceHarness
{
    public const SLUG           = 'race';
    public const VERSIONED_SLUG = 'race_versioned';

    /**
     * @param array<string, mixed> $params DBAL connection parameters
     */
    public static function entityManager(array $params): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection($params, $config), $config);
    }

    /**
     * SQLite file parameters: no busy wait, so a lock held by the other connection fails at once ("database is locked",
     * a DBAL LockWaitTimeoutException).
     *
     * @return array<string, mixed>
     */
    public static function sqliteParams(string $path): array
    {
        return ['driver' => 'pdo_sqlite', 'path' => $path, 'driverOptions' => [PDO::ATTR_TIMEOUT => 0]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function urlParams(string $url): array
    {
        return (new DsnParser([
            'mysql'     => 'pdo_mysql',
            'pdo-mysql' => 'pdo_mysql',
            'mariadb'   => 'pdo_mysql',
            'pgsql'     => 'pdo_pgsql',
            'pdo-pgsql' => 'pdo_pgsql',
            'postgres'  => 'pdo_pgsql',
        ]))->parse($url);
    }

    public static function createSchema(EntityManagerInterface $manager): void
    {
        $metadata = [
            $manager->getClassMetadata(RaceSubject::class),
            $manager->getClassMetadata(VersionedRaceSubject::class),
        ];
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public static function dropSchema(EntityManagerInterface $manager): void
    {
        (new SchemaTool($manager))->dropSchema([
            $manager->getClassMetadata(RaceSubject::class),
            $manager->getClassMetadata(VersionedRaceSubject::class),
        ]);
    }

    /**
     * draft --submit--> review --approve--> done, with an automatic `close` from done to closed when $automatic.
     */
    public static function definition(string $slug = self::SLUG, string $subjectClass = RaceSubject::class, bool $automatic = false): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Race', $slug, 'draft', $subjectClass);
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('review', null, 1));
        $definition->addPlace(new WorkflowPlace('done', null, 2));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['done']));
        if ($automatic) {
            $definition->addPlace(new WorkflowPlace('closed', null, 3));
            $close = new WorkflowTransition('close', ['done'], ['closed']);
            $close->setAutomatic(true);
            $definition->addTransition($close);
        }

        return $definition;
    }

    /**
     * @param list<WorkflowDefinition> $definitions Default: the `race` and `race_versioned` workflows
     */
    public static function applicator(
        EntityManagerInterface $manager,
        WorkflowConcurrencyPolicy $policy = new WorkflowConcurrencyPolicy(),
        bool $flushAfterApply = true,
        ?ManagerRegistry $registry = null,
        array $definitions = [],
    ): WorkflowApplicator {
        $definitions = $definitions === [] ? [self::definition(), self::definition(self::VERSIONED_SLUG, VersionedRaceSubject::class)] : $definitions;
        $repository  = FixedDefinitionRepository::with(...$definitions);

        return new WorkflowApplicator(
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
            $repository,
            new WorkflowResolver($repository),
            $manager,
            $registry,
            $flushAfterApply,
            concurrency: $policy,
        );
    }

    /**
     * The status column as committed (read on the given connection, outside any entity manager).
     */
    public static function storedStatus(EntityManagerInterface $manager, int $id, string $table = 'wfrt_race_subject'): mixed
    {
        return $manager->getConnection()->fetchOne('SELECT status FROM ' . $table . ' WHERE id = ?', [$id]);
    }
}
