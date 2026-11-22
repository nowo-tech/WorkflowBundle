<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\WorkflowBundle\EventListener\WorkflowConflictResponseSubscriber;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\MissedDraftDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;
use function array_merge;
use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function implode;
use function in_array;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_open;
use function range;
use function sort;
use function str_starts_with;
use function stream_set_timeout;
use function trim;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 rule 4 for the definition version numbers (since 2.2) against a real server with real row locks:
 * the scenarios of ConcurrentVersionScenarios, two processes claiming numbers of one scope (the first claim included),
 * a draft race inside the host's transaction, a first claim hidden by a REPEATABLE READ snapshot, and a deadlock on the
 * claim inside the host's transaction (audit ZH-M1: the connection is repaired, the answer is a retryable conflict).
 */
abstract class AbstractConcurrentVersionRealDatabaseTestCase extends TestCase
{
    use ConcurrentVersionScenarios;

    protected string $url = '';

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $url = getenv(static::urlVariable());
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(static::urlVariable() . ' is not set (real-database race tests).');
        }
        $this->url = $url;
    }

    protected function connect(): EntityManagerInterface
    {
        return IntegrationEntityManagerFactory::createForParams(RaceHarness::urlParams($this->url));
    }

    /**
     * Two processes insert 10 versions each at the same moment, starting with the scope's first claim (no counter row
     * yet): every number is handed out once, nothing fails.
     */
    #[Test]
    public function twoProcessesClaimingVersionsOfOneScopeNeverShareANumber(): void
    {
        [$a, $tool, $metadata] = $this->prepareVersions();

        try {
            $racers = [$this->child(['claim', $this->url, self::VERSIONS_SLUG, '10']), $this->child(['claim', $this->url, self::VERSIONS_SLUG, '10'])];
            foreach ($racers as [, $pipes]) {
                self::assertSame('ready', trim((string) fgets($pipes[1])));
            }
            foreach ($racers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }
            $claimed = [];
            foreach ($racers as [$process, $pipes]) {
                $line = trim((string) fgets($pipes[1]));
                fclose($pipes[1]);
                proc_close($process);
                self::assertStringStartsNotWith('error:', $line);
                $claimed = array_merge($claimed, array_map('intval', explode(',', $line)));
            }
            sort($claimed);

            self::assertSame(range(2, 21), $claimed, 'every number once');
            self::assertSame(range(1, 21), $this->storedVersions($a));
            self::assertSame(21, $this->counter($a));
        } finally {
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * The loser runs inside a transaction the host opened, whose snapshot predates the winner's draft (its plain reads
     * miss it). Its claim waits for the winner, and the scope is then read as last committed (the 2.1.1 locking read on
     * MySQL / MariaDB): the winner's draft is the answer, and the host's transaction stays usable. With MariaDB's
     * `innodb_snapshot_isolation` the claim of a counter row changed after the snapshot fails instead: a retryable
     * conflict.
     */
    #[Test]
    public function aDraftRaceInsideTheHostsTransactionEndsWithTheWinnersDraft(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $b                                 = $this->connect();
        $host                              = $b->getConnection();
        $this->useSavepoints($host);

        try {
            // The counter exists before the host's snapshot (a scope drafted before).
            $this->insertCopy($this->versionRepository($a), $published);
            $this->publishAll($a);

            $loser = (new MissedDraftDefinitionRepository(new TestManagerRegistry($b)))->holdPlainReads();
            $twin  = $loser->findCurrent(self::VERSIONS_SLUG, null);
            self::assertNotNull($twin);
            $host->beginTransaction();
            self::assertSame([1, 2], $this->storedVersions($b), 'the host read the scope: its snapshot starts here');

            $winner = (new WorkflowVersioning($this->versionRepository($a)))->createDraftOf($published);
            self::assertSame(3, $winner->getVersion());

            if ($this->snapshotIsolation($host)) {
                try {
                    (new WorkflowVersioning($loser))->createDraftOf($twin);
                    self::fail('MariaDB innodb_snapshot_isolation refuses the claim of a row changed after the snapshot.');
                } catch (TransientStorageFailure $conflict) {
                    self::assertTrue(WorkflowConflictResponseSubscriber::isConflict($conflict));
                }
                $this->assertEndedByTheServerAndUsable($host);
                self::assertSame([1, 2, 3], $this->storedVersions($a), 'one draft');

                return;
            }
            $result = (new WorkflowVersioning($loser))->createDraftOf($twin);
            self::assertSame($winner->getId(), $result->getId(), 'the winner\'s draft, read as last committed');
            self::assertTrue($host->isTransactionActive(), 'the host\'s transaction is never ended by the bundle');
            self::assertSame(1, $host->getTransactionNestingLevel());
            self::assertFalse($host->isRollbackOnly());
            $host->commit();

            self::assertSame([1, 2, 3], $this->storedVersions($a), 'one draft');
        } finally {
            while ($host->isTransactionActive()) {
                $host->rollBack();
            }
            $host->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * A REPEATABLE READ transaction whose snapshot predates the scope's first claim by another connection. On MySQL /
     * MariaDB (without `innodb_snapshot_isolation`) the claim's UPDATE reads the latest committed row: the next number.
     * On PostgreSQL the committed counter row is invisible to the snapshot (and MariaDB's snapshot isolation refuses
     * it): a retryable conflict, nothing written, the transaction still usable.
     */
    #[Test]
    public function aFirstClaimCommittedAfterARepeatableReadSnapshotGivesTheNextNumberOrARetryableConflict(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $b                                 = $this->connect();
        $host                              = $b->getConnection();
        $this->useSavepoints($host);
        $host->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);

        try {
            $host->beginTransaction();
            self::assertSame([1], $this->storedVersions($b), 'the snapshot starts here');
            self::assertSame(2, $this->insertCopy($this->versionRepository($a), $published), 'the first claim, committed');

            $repository       = $this->versionRepository($b);
            $isolatedSnapshot = $this->snapshotIsolation($host);
            if ($host->getDatabasePlatform() instanceof AbstractMySQLPlatform && !$isolatedSnapshot) {
                self::assertSame(3, $this->insertCopy($repository, $published));
            } else {
                try {
                    $this->insertCopy($repository, $published);
                    self::fail('The committed counter row is hidden by the snapshot.');
                } catch (TransientStorageFailure $conflict) {
                    self::assertTrue(WorkflowConflictResponseSubscriber::isConflict($conflict));
                }
            }
            if ($isolatedSnapshot) {
                // MariaDB rolls the whole transaction back on ER_CHECKREAD (1020), savepoints included.
                $this->assertEndedByTheServerAndUsable($host);

                return;
            }
            self::assertSame(1, $host->getTransactionNestingLevel());
            self::assertFalse($host->isRollbackOnly(), 'the savepoint was rolled back, the host\'s transaction is usable');
            self::assertSame(1, (int) $host->fetchOne('SELECT 1'));
            $host->commit();
        } finally {
            while ($host->isTransactionActive()) {
                $host->rollBack();
            }
            $host->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * Audit ZH-M1. The host's transaction claims a version of scope A; another process holds the counter of scope B and
     * then asks for A's; the host's claim of B closes the cycle. The server picks the host as the deadlock victim (the
     * other process wrote far more). The answer is a retryable conflict (the deadlock is its previous exception), never
     * a 500, and the connection is left usable:
     *
     * - MySQL / MariaDB rolled the whole host transaction back and dropped its savepoints: ROLLBACK TO SAVEPOINT fails
     *   (1305), so the runner closes the connection (nesting level 0); the next statement reconnects;
     * - PostgreSQL rolls back to the runner's savepoint: the host's transaction (level 1) keeps its earlier claim and
     *   commits.
     */
    #[Test]
    public function aDeadlockOnTheClaimInsideTheHostsTransactionIsARetryableConflictAndTheConnectionIsRepaired(): void
    {
        [$a, $tool, $metadata, $published] = $this->prepareVersions();
        $b                                 = $this->connect();
        $host                              = $b->getConnection();
        $this->useSavepoints($host);
        $other = null;

        try {
            // Both counters exist: version 2 of scope A (the published slug) and version 1 of scope B.
            $this->insertCopy($this->versionRepository($a), $published);
            $a->getConnection()->insert('workflow_definition_sequence', ['tenant_key' => '', 'slug' => 'race_versions_b', 'last_version' => 1]);

            $other = $this->child(['hold', $this->url, 'workflow_definition_sequence', 'race_versions_b', self::VERSIONS_SLUG]);
            self::assertSame('ready', trim((string) fgets($other[1][1])));

            $host->beginTransaction();
            $repository = $this->versionRepository($b);
            self::assertSame(3, $this->insertCopy($repository, $published), 'the host holds the counter of scope A');
            fwrite($other[1][0], "go\n");
            fclose($other[1][0]);

            try {
                $repository->insertNextVersion('race_versions_b', null, static fn (int $version): int => $version);
                self::fail('Deadlock expected.');
            } catch (TransientStorageFailure $conflict) {
                self::assertTrue(WorkflowConflictResponseSubscriber::isConflict($conflict));
                self::assertTrue($this->hasDeadlock($conflict), 'the original deadlock is kept as the previous exception');
            }

            if ($host->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                self::assertSame(1, $host->getTransactionNestingLevel(), 'rolled back to the runner\'s savepoint only');
                self::assertFalse($host->isRollbackOnly());
                $host->commit();
                self::assertSame([1, 2, 3], $this->storedVersions($a), 'the host\'s earlier claim is committed');
            } else {
                $this->assertEndedByTheServerAndUsable($host);
                self::assertSame([1, 2], $this->storedVersions($a), 'nothing of the host\'s transaction survived');
            }

            self::assertSame('ok', trim((string) fgets($other[1][1])), 'the other process committed');
            self::assertSame(2, (int) $a->getConnection()->fetchOne("SELECT last_version FROM workflow_definition_sequence WHERE tenant_key = '' AND slug = ?", ['race_versions_b']), 'only the other process moved scope B');
        } finally {
            while ($host->isTransactionActive()) {
                try {
                    $host->rollBack();
                } catch (Throwable) {
                    $host->close();
                }
            }
            if ($other !== null) {
                proc_close($other[0]);
            }
            $host->close();
            $this->dropVersions($a, $tool, $metadata);
        }
    }

    /**
     * MySQL / MariaDB rolled the whole transaction back (deadlock, ER_CHECKREAD) and dropped its savepoints: the runner's
     * ROLLBACK TO SAVEPOINT failed (1305), so it closed the connection instead of leaving it at a raised nesting level
     * (audit ZH-M1); the next statement reconnects.
     */
    private function assertEndedByTheServerAndUsable(Connection $host): void
    {
        self::assertSame(0, $host->getTransactionNestingLevel(), 'closed by the runner, not left at a raised nesting level');
        self::assertSame(1, (int) $host->fetchOne('SELECT 1'), 'the connection is usable again');
    }

    private function hasDeadlock(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
        }

        return false;
    }

    private function publishAll(EntityManagerInterface $manager): void
    {
        $manager->getConnection()->executeStatement("UPDATE workflow_definition SET status = 'published' WHERE slug = ?", [self::VERSIONS_SLUG]);
    }

    /**
     * A server with `innodb_snapshot_isolation` on (MariaDB), whatever platform class DBAL picked.
     */
    private function snapshotIsolation(Connection $connection): bool
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return false;
        }
        $row = $connection->fetchNumeric("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'");

        return $row !== false && in_array($row[1] ?? null, ['ON', '1'], true);
    }

    /**
     * DBAL 3: nest the bundle's unit in a savepoint (DoctrineBundle's `use_savepoints`); DBAL 4 always does.
     */
    private function useSavepoints(Connection $connection): void
    {
        if (str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.')) {
            $connection->setNestTransactionsWithSavepoints(true);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function child(array $arguments): array
    {
        $script = dirname(__DIR__, 2) . '/Support/Concurrency/version-child.php';
        $line   = PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test-only CLI child; its reads are bounded below)
        $process = proc_open($line, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 60);

        return [$process, $pipes];
    }
}
