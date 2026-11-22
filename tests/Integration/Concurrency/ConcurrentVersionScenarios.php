<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\EventListener\WorkflowConflictResponseSubscriber;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\MissedDraftDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function array_map;
use function dirname;
use function is_numeric;
use function sort;

use const PHP_VERSION_ID;

/**
 * REQ-RUNTIME-003 rule 4 for the definition version numbers (since 2.2): the next number is claimed from the scope's
 * counter row (`workflow_definition_sequence`) in the transaction that inserts the version, never computed as
 * `MAX(version)+1`. The using class provides `connect()`, a new connection (and entity manager) on the shared database.
 */
trait ConcurrentVersionScenarios
{
    private const VERSIONS_SLUG = 'race_versions';

    abstract protected function connect(): EntityManagerInterface;

    #[Test]
    public function theFirstClaimSeedsTheCounterAfterTheStoredVersions(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();

        try {
            $repository = $this->versionRepository($a);
            // A version stored before 2.2 (no counter row yet): the first claim starts after it.
            $repository->save($published->copyAsDraft(null, 4));

            self::assertSame(5, $this->insertCopy($repository, $published));
            self::assertSame(5, $this->counter($a));
            self::assertSame(6, $this->insertCopy($repository, $published));
            self::assertSame([1, 4, 5, 6], $this->storedVersions($a));
            self::assertSame(0, $a->getConnection()->getTransactionNestingLevel());
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function aRolledBackWriteGivesItsNumberBack(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();

        try {
            $repository = $this->versionRepository($a);
            $refused    = new RuntimeException('refused');
            try {
                $repository->insertNextVersion(self::VERSIONS_SLUG, null, static function (int $version) use ($repository, $published, $refused): int {
                    $repository->save($published->copyAsDraft(null, $version));
                    if ($version > 0) {
                        throw $refused;
                    }

                    return $version;
                });
                self::fail('The write refused.');
            } catch (RuntimeException $exception) {
                self::assertSame($refused, $exception, 'a domain failure is rethrown unchanged');
            }
            self::assertSame(1, $this->counter($a), 'the counter row was seeded on its own; the claim was rolled back with the write');
            self::assertSame(0, $a->getConnection()->getTransactionNestingLevel());

            $repository = $this->versionRepository($a);
            self::assertSame(2, $this->insertCopy($repository, $published));
            self::assertSame([1, 2], $this->storedVersions($a));
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function draftsTakeTheCounterAndADeletedNumberIsNeverHandedOutAgain(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();

        try {
            $versioning = new WorkflowVersioning($this->versionRepository($a));
            $draft      = $versioning->createDraftOf($published);
            self::assertSame(2, $draft->getVersion());
            self::assertSame($draft, $versioning->createDraftOf($published), 'the open draft is the draft');

            $versioning->delete($draft);
            $again = $versioning->createDraftOf($published);
            self::assertSame(3, $again->getVersion(), 'the counter never goes back');
            $versioning->publish($again);
            self::assertSame(4, $versioning->createDraftOf($again)->getVersion());
            self::assertSame([1, 3, 4], $this->storedVersions($a));
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function aDraftCommittedByAnotherConnectionIsFoundUnderTheClaim(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $b                                 = $this->connect();

        try {
            $winner = (new WorkflowVersioning($this->versionRepository($a)))->createDraftOf($published);
            // B looked for a draft before A committed (its fast path missed): the claim waits for A, then finds A's draft.
            $missed = new MissedDraftDefinitionRepository(new TestManagerRegistry($b));
            $twin   = $missed->findCurrent(self::VERSIONS_SLUG, null);
            self::assertInstanceOf(WorkflowDefinition::class, $twin);
            $result = (new WorkflowVersioning($missed))->createDraftOf($twin);

            self::assertSame($winner->getId(), $result->getId());
            self::assertSame([1, 2], $this->storedVersions($a), 'one draft');
            self::assertSame(3, $this->counter($a), 'the loser\'s claim was committed: a skipped number, never a second draft');
        } finally {
            $b->getConnection()->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function aCounterBehindTheStoredVersionsIsRealignedAndNeverMovedDown(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();

        try {
            $repository = $this->versionRepository($a);
            self::assertSame(2, $this->insertCopy($repository, $published));
            self::assertSame(3, $this->insertCopy($repository, $published));
            // Versions written without the counter (a host's own SQL, a slug renamed into the scope).
            $this->setCounter($a, 1);

            self::assertSame(4, $this->insertCopy($repository, $published), 'the counter is moved up to 3, the write runs again');
            self::assertSame(4, $this->counter($a));

            $this->setCounter($a, 10);
            self::assertSame(11, $this->insertCopy($repository, $published), 'a counter ahead of the versions is kept');
            self::assertSame([1, 2, 3, 4, 11], $this->storedVersions($a));
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function aCollisionTheCounterDoesNotExplainIsRethrown(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();

        try {
            $repository = $this->versionRepository($a);
            try {
                // A write that ignores the claimed number and reuses version 1.
                $repository->insertNextVersion(self::VERSIONS_SLUG, null, static function () use ($repository, $published): void {
                    $repository->save($published->copyAsDraft(null, 1));
                });
                self::fail('The unique (tenant_key, slug, version) key refuses version 1.');
            } catch (UniqueConstraintViolationException) {
            }
            self::assertSame(0, $a->getConnection()->getTransactionNestingLevel());
            self::assertSame(1, $this->counter($a), 'only the seeded counter row: the claim was rolled back');
            self::assertSame([1], $this->storedVersions($a));
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    #[Test]
    public function aClaimHeldByAnotherTransactionIsARetryableConflictAndLeavesTheConnectionUsable(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $b                                 = $this->connect();
        $holder                            = $a->getConnection();

        try {
            $this->insertCopy($this->versionRepository($a), $published);
            $this->shortLockWait($b->getConnection());
            $holder->beginTransaction();
            $holder->executeStatement("UPDATE workflow_definition_sequence SET last_version = last_version + 1 WHERE tenant_key = '' AND slug = ?", [self::VERSIONS_SLUG]);

            try {
                $this->insertCopy($this->versionRepository($b), $published);
                self::fail('The counter row is locked by the other transaction.');
            } catch (TransientStorageFailure $conflict) {
                self::assertTrue(WorkflowConflictResponseSubscriber::isConflict($conflict), '409 + CONFLICT_001 on the bundle\'s routes');
            }
            self::assertSame(0, $b->getConnection()->getTransactionNestingLevel(), 'rolled back to where it started');
            self::assertSame([1, 2], $this->storedVersions($b));

            $holder->rollBack();
            self::assertSame(3, $this->insertCopy($this->versionRepository($b), $published), 'the retry gets the next number');
        } finally {
            while ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $b->getConnection()->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * Audit ZI-L1: with an existing counter row, a claim conflict commits nothing in the request (the counter check is a
     * plain autocommit read, never a unit of its own), so commit detection answers CONFLICT_001, not CONFLICT_002.
     */
    #[Test]
    public function aClaimConflictOnAnExistingCounterCommitsNothingAndIsRetrySafe(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $ledger                            = new CommitLedger();
        $b                                 = $this->trackedConnect($a, $ledger);
        $holder                            = $a->getConnection();

        try {
            $this->insertCopy($this->versionRepository($a), $published);
            $this->shortLockWait($b->getConnection());
            $holder->beginTransaction();
            $holder->executeStatement("UPDATE workflow_definition_sequence SET last_version = last_version + 1 WHERE tenant_key = '' AND slug = ?", [self::VERSIONS_SLUG]);

            $mark = new CommitMark($ledger);
            try {
                $this->insertCopy($this->versionRepository($b), $published);
                self::fail('The counter row is locked by the other transaction.');
            } catch (TransientStorageFailure $conflict) {
                self::assertFalse($mark->committedSince(), 'nothing was committed in this request');
                self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict, $mark));
            }
        } finally {
            while ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $b->getConnection()->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * Another manager on the same database whose connection records its commits in $ledger (transaction-doctrine's
     * commit detection, as a host with `commit_detection` on has it).
     */
    protected function trackedConnect(EntityManagerInterface $manager, CommitLedger $ledger): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setMiddlewares([new CommitTrackingMiddleware($ledger)]);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection($manager->getConnection()->getParams(), $config), $config);
    }

    /**
     * The repository on a private manager of $manager (as the bundle wires it): a manager a failed flush closed is
     * replaced on its next use.
     */
    protected function versionRepository(EntityManagerInterface $manager): WorkflowDefinitionRepository
    {
        return new WorkflowDefinitionRepository(new TestManagerRegistry(new WorkflowEntityManager(new TestManagerRegistry($manager))));
    }

    /**
     * Inserts a draft copy of $source with the next version number and returns that number.
     */
    protected function insertCopy(WorkflowDefinitionRepository $repository, WorkflowDefinition $source): int
    {
        return $repository->insertNextVersion(self::VERSIONS_SLUG, null, static function (int $version) use ($repository, $source): int {
            $repository->save($source->copyAsDraft(null, $version));

            return $version;
        });
    }

    /**
     * @return list<int>
     */
    protected function storedVersions(EntityManagerInterface $manager): array
    {
        $versions = array_map(static fn (mixed $version): int => is_numeric($version) ? (int) $version : -1, $manager->getConnection()->fetchFirstColumn('SELECT version FROM workflow_definition WHERE slug = ?', [self::VERSIONS_SLUG]));
        sort($versions);

        return $versions;
    }

    protected function counter(EntityManagerInterface $manager): ?int
    {
        $value = $manager->getConnection()->fetchOne("SELECT last_version FROM workflow_definition_sequence WHERE tenant_key = '' AND slug = ?", [self::VERSIONS_SLUG]);

        return is_numeric($value) ? (int) $value : null;
    }

    protected function setCounter(EntityManagerInterface $manager, int $value): void
    {
        $manager->getConnection()->executeStatement("UPDATE workflow_definition_sequence SET last_version = ? WHERE tenant_key = '' AND slug = ?", [$value, self::VERSIONS_SLUG]);
    }

    /**
     * A lock held by the other connection fails within a second (SQLite: at once, no busy wait).
     */
    protected function shortLockWait(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '1s'");
        }
    }

    /**
     * A fresh schema with version 1 of the slug published.
     *
     * @return array{0: EntityManagerInterface, 1: SchemaTool, 2: list<ClassMetadata<object>>, 3: WorkflowDefinition}
     */
    protected function prepareVersions(): array
    {
        $manager  = $this->connect();
        $tool     = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $published = new WorkflowDefinition('Race versions', self::VERSIONS_SLUG, 'draft', 'App\\Entity\\Race');
        $published->addPlace(new WorkflowPlace('draft', null, 0));
        $published->addPlace(new WorkflowPlace('review', null, 1));
        $published->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        $published->publish();
        $manager->persist($published);
        $manager->flush();

        return [$manager, $tool, $metadata, $published];
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     */
    protected function dropVersions(EntityManagerInterface $manager, SchemaTool $tool, array $metadata): void
    {
        $connection = $manager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $tool->dropSchema($metadata);
        $connection->close();
    }
}
