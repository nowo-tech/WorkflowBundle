<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\StaleReadDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function in_array;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_open;
use function sort;
use function str_starts_with;
use function stream_set_timeout;
use function trim;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 for the template install against a real server: the scenarios of ConcurrentInstallScenarios plus two
 * processes racing for real.
 */
abstract class AbstractConcurrentInstallRealDatabaseTestCase extends TestCase
{
    use ConcurrentInstallScenarios;

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

    #[Test]
    public function twoProcessesInstallingTheSameTemplateLeaveOneDefinition(): void
    {
        [$a, $tool, $metadata] = $this->prepare();

        try {
            $racers = [$this->racer('org-a'), $this->racer('org-a')];
            foreach ($racers as [, $pipes]) {
                self::assertSame('ready', trim((string) fgets($pipes[1])));
            }
            foreach ($racers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($racers as [$process, $pipes]) {
                $results[] = trim((string) fgets($pipes[1]));
                fclose($pipes[1]);
                proc_close($process);
            }
            sort($results);

            self::assertSame(['created', 'existing'], $results);
            self::assertSame(1, $this->tenantRows($a, 'org-a'));
        } finally {
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
        }
    }

    /**
     * Audit YP-L1 (2.1.1): the loser runs inside a transaction the host opened, and that transaction read the table
     * before the winner committed (on MySQL / MariaDB that read fixes its REPEATABLE READ snapshot, so the loser's
     * plain reads cannot see the winner). The unique key refuses the loser's write; the refused write rolls back to
     * the savepoint only, the winner is read as last committed and answered with `created = false`, and the host's
     * transaction keeps its own earlier write and commits.
     */
    #[Test]
    public function aLoserInsideTheHostsTransactionGetsTheWinnersDefinitionAndTheTransactionStaysUsable(): void
    {
        [$a, $tool, $metadata] = $this->prepare();
        $b                     = $this->connect();
        $host                  = $b->getConnection();
        $this->useSavepoints($host);

        try {
            $tenant = new WorkflowTenant('org-a');
            $loser  = $this->loserCatalog($b);
            $host->beginTransaction();
            // The host's own work in its transaction (it also reads the scope: the snapshot predates the winner).
            self::assertTrue($loser->installTemplate(self::TEMPLATE, new WorkflowTenant('org-b'))->created);
            self::assertSame(0, $this->tenantRows($b, 'org-a'));

            $winner = $this->catalog($a)->installTemplate(self::TEMPLATE, $tenant);
            if ($this->snapshotIsolation($host)) {
                // MariaDB with innodb_snapshot_isolation (default since 11.6.2): a locking read of the newer row would
                // fail, so the bundle does not try it; the documented LogicException, the transaction stays usable.
                try {
                    $loser->installTemplate(self::TEMPLATE, $tenant);
                    self::fail('LogicException expected');
                } catch (LogicException $exception) {
                    self::assertStringContainsString('snapshot predates it', $exception->getMessage());
                }
                self::assertFalse($host->isRollbackOnly());
                $host->commit();
                self::assertSame(1, $this->tenantRows($a, 'org-a'), 'exactly one definition for the tenant');
                self::assertSame(1, $this->tenantRows($a, 'org-b'), 'the host\'s own write is committed');

                return;
            }
            $result = $loser->installTemplate(self::TEMPLATE, $tenant);

            self::assertTrue($winner->created);
            self::assertFalse($result->created, 'the loser answers the winner, it does not throw');
            self::assertSame($winner->definition->ref->id, $result->definition->ref->id);
            self::assertSame(['draft', 'review'], $result->definition->placeNames, 'the winner\'s places are read as committed');
            self::assertSame(['submit'], $result->definition->transitionNames);
            self::assertTrue($host->isTransactionActive(), 'the host\'s transaction is never ended by the bundle');
            self::assertSame(1, $host->getTransactionNestingLevel());
            self::assertFalse($host->isRollbackOnly());

            $host->commit();
            self::assertSame(1, $this->tenantRows($a, 'org-a'), 'exactly one definition for the tenant');
            self::assertSame(1, $this->tenantRows($a, 'org-b'), 'the host\'s own write is committed');
        } finally {
            while ($host->isTransactionActive()) {
                $host->rollBack();
            }
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
            $host->close();
        }
    }

    /**
     * Audit YS (2.1.1): in a READ COMMITTED session the plain read already sees the committed winner, so no locking read
     * runs; another host installing a newer definition (another tenant) is not held up by the loser's open transaction.
     */
    #[Test]
    public function aReadCommittedLoserInsideTheHostsTransactionTakesNoLockingReadAndBlocksNoOtherInstall(): void
    {
        [$a, $tool, $metadata] = $this->prepare();
        $b                     = $this->connect();
        $host                  = $b->getConnection();
        $this->useSavepoints($host);
        $host->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
        $c     = $this->connect();
        $other = $c->getConnection();
        $other->executeStatement($other->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 3' : "SET lock_timeout = '3s'");

        try {
            $tenant = new WorkflowTenant('org-a');
            $host->beginTransaction();
            self::assertSame(0, $this->tenantRows($b, 'org-a'));
            $winner = $this->catalog($a)->installTemplate(self::TEMPLATE, $tenant);
            $result = $this->loserCatalog($b)->installTemplate(self::TEMPLATE, $tenant);

            self::assertFalse($result->created);
            self::assertSame($winner->definition->ref->id, $result->definition->ref->id);
            self::assertSame(['draft', 'review'], $result->definition->placeNames);
            // A newer definition (places and transitions included) for another tenant, while the loser's host is open.
            self::assertTrue($this->catalog($c)->installTemplate(self::TEMPLATE, new WorkflowTenant('org-z'))->created);

            self::assertTrue($host->isTransactionActive());
            self::assertFalse($host->isRollbackOnly());
            $host->commit();
            self::assertSame(1, $this->tenantRows($a, 'org-a'));
        } finally {
            while ($host->isTransactionActive()) {
                $host->rollBack();
            }
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
            $host->close();
            $other->close();
        }
    }

    /**
     * Audit YP-L1 (2.1.1), the documented exception: on DBAL 3 with savepoints off the refused write cannot roll back
     * to a savepoint and marks the host's transaction rollback-only. The loser then reads nothing and throws a
     * LogicException; the bundle never rolls the host's transaction back (the host does).
     */
    #[Test]
    public function withoutSavepointsTheLoserInsideTheHostsTransactionThrowsAndLeavesTheTransactionToTheHost(): void
    {
        if (!$this->savepointsCanBeOff()) {
            // DBAL 4 always nests transactions with savepoints: covered by the test above.
            self::addToAssertionCount(1);

            return;
        }
        [$a, $tool, $metadata] = $this->prepare();
        $b                     = $this->connect();
        $host                  = $b->getConnection();

        try {
            $tenant = new WorkflowTenant('org-a');
            $loser  = $this->loserCatalog($b);
            $host->beginTransaction();
            self::assertSame(0, $this->tenantRows($b, 'org-a'));
            self::assertTrue($this->catalog($a)->installTemplate(self::TEMPLATE, $tenant)->created);

            try {
                $loser->installTemplate(self::TEMPLATE, $tenant);
                self::fail('LogicException expected');
            } catch (LogicException $exception) {
                self::assertStringContainsString('rollback-only', $exception->getMessage());
            }
            self::assertTrue($host->isTransactionActive(), 'the bundle never rolls back the host\'s transaction');
            self::assertTrue($host->isRollbackOnly());

            $host->rollBack();
            self::assertSame(1, $this->tenantRows($a, 'org-a'), 'exactly one definition for the tenant');
        } finally {
            while ($host->isTransactionActive()) {
                $host->rollBack();
            }
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
            $host->close();
        }
    }

    /**
     * The loser's catalog. On MySQL / MariaDB the plain repository: the host's REPEATABLE READ snapshot hides the
     * winner from every plain read for real. PostgreSQL (READ COMMITTED) has no such snapshot, so the plain reads are
     * held stale to force the same path (the as-committed read is never faked).
     */
    private function loserCatalog(EntityManagerInterface $manager): WorkflowDefinitionCatalog
    {
        $repository = $manager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? new WorkflowDefinitionRepository(new TestManagerRegistry($manager))
            : (new StaleReadDefinitionRepository(new TestManagerRegistry($manager)))->holdUntilCatchUp();

        return new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);
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
     * DBAL 3: nest the private manager's unit in a savepoint (DoctrineBundle's `use_savepoints`); DBAL 4 always does.
     */
    private function useSavepoints(Connection $connection): void
    {
        if ($this->savepointsCanBeOff()) {
            $connection->setNestTransactionsWithSavepoints(true);
        }
    }

    /**
     * DBAL 3, where nested transactions use savepoints only when asked to (off by default for a hand-built connection).
     */
    private function savepointsCanBeOff(): bool
    {
        return str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.');
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function racer(string $tenant): array
    {
        $script = dirname(__DIR__, 2) . '/Support/Concurrency/install-child.php';
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test-only CLI child; its reads are bounded below)
        $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->url) . ' ' . escapeshellarg(self::TEMPLATE) . ' ' . escapeshellarg($tenant), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 30);

        return [$process, $pipes];
    }
}
