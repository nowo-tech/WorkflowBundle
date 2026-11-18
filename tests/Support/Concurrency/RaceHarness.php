<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Concurrency\WorkflowConcurrencyPolicy;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use PDO;

use function is_array;
use function json_decode;
use function ksort;

use const PHP_VERSION_ID;

/**
 * Two (or more) entity managers on one database, each with its own connection, for the REQ-RUNTIME-003 race tests:
 * a SQLite file (default suite) or a real server (`NOWO_WORKFLOW_TEST_*_URL`, group `database`).
 */
final class RaceHarness
{
    public const SLUG           = 'race';
    public const VERSIONED_SLUG = 'race_versioned';
    public const PETRI_SLUG     = 'race_petri';
    public const INHERITED_SLUG = 'race_inherited';
    public const ENUM_SLUG      = 'race_enum';

    /**
     * @param array<string, mixed> $params DBAL connection parameters
     * @param LockingSqlMiddleware|null $locking A SQLite file behaving as a MySQL server for the SQL the bundle writes
     */
    public static function entityManager(array $params, ?LockingSqlMiddleware $locking = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        if ($locking instanceof LockingSqlMiddleware) {
            $config->setMiddlewares([$locking]);
        }
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection($params, $config);
        // DBAL 3: nested transactions as savepoints, as DoctrineBundle configures them (DBAL 4 always uses them).
        $connection->setNestTransactionsWithSavepoints(true);

        return new EntityManager($connection, $config);
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
        $tool = new SchemaTool($manager);
        $tool->dropSchema(self::metadata($manager));
        $tool->createSchema(self::metadata($manager));
    }

    public static function dropSchema(EntityManagerInterface $manager): void
    {
        (new SchemaTool($manager))->dropSchema(self::metadata($manager));
    }

    /**
     * start --fork--> left + right; left --left--> left_done; right --right--> right_done;
     * left_done + right_done --join--> end (a Petri net: JSON place => tokens marking).
     */
    public static function petriDefinition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Race petri', self::PETRI_SLUG, 'start', PetriRaceSubject::class, WorkflowType::Workflow);
        $definition->setMarkingProperty('marking');
        foreach (['start', 'left', 'right', 'left_done', 'right_done', 'end'] as $position => $place) {
            $definition->addPlace(new WorkflowPlace($place, null, $position));
        }
        $definition->addTransition(new WorkflowTransition('fork', ['start'], ['left', 'right']));
        $definition->addTransition(new WorkflowTransition('left', ['left'], ['left_done']));
        $definition->addTransition(new WorkflowTransition('right', ['right'], ['right_done']));
        $definition->addTransition(new WorkflowTransition('join', ['left_done', 'right_done'], ['end']));

        return $definition;
    }

    /**
     * @return list<WorkflowDefinition> every race workflow
     */
    public static function definitions(): array
    {
        return [
            self::definition(),
            self::definition(self::VERSIONED_SLUG, VersionedRaceSubject::class),
            self::petriDefinition(),
            self::definition(self::INHERITED_SLUG, InheritedRaceSubject::class),
            self::definition(self::ENUM_SLUG, EnumRaceSubject::class),
        ];
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private static function metadata(EntityManagerInterface $manager): array
    {
        return [
            $manager->getClassMetadata(RaceSubject::class),
            $manager->getClassMetadata(VersionedRaceSubject::class),
            $manager->getClassMetadata(PetriRaceSubject::class),
            $manager->getClassMetadata(InheritedRaceSubject::class),
            $manager->getClassMetadata(InheritedRaceSubjectChild::class),
            $manager->getClassMetadata(EnumRaceSubject::class),
        ];
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
     * @param list<WorkflowDefinition> $definitions Default: every race workflow ({@see self::definitions()})
     */
    public static function applicator(
        EntityManagerInterface $manager,
        WorkflowConcurrencyPolicy $policy = new WorkflowConcurrencyPolicy(),
        bool $flushAfterApply = true,
        ?ManagerRegistry $registry = null,
        array $definitions = [],
    ): WorkflowApplicator {
        $definitions = $definitions === [] ? self::definitions() : $definitions;
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

    /**
     * The stored Petri-net marking, decoded, places sorted.
     *
     * @return array<string, int>
     */
    public static function storedPetriMarking(EntityManagerInterface $manager, int $id): array
    {
        $marking = json_decode((string) $manager->getConnection()->fetchOne('SELECT marking FROM wfrt_petri_race_subject WHERE id = ?', [$id]), true);
        $marking = is_array($marking) ? $marking : [];
        ksort($marking);

        return $marking;
    }
}
