<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\LockingSqlMiddleware;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\StaleReadDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function dirname;
use function implode;
use function is_file;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_VERSION_ID;

/**
 * Audit YP-L1 (2.1.1) in the default suite: a SQLite file stands in for a MySQL / MariaDB server
 * ({@see LockingSqlMiddleware}), so the loser of an install race inside the host's transaction reads the winner with
 * the locking read the bundle sends to InnoDB, and skips it in a READ COMMITTED session and with MariaDB's
 * `innodb_snapshot_isolation` on (audit YS: read from the session variables, never from the platform class). The real
 * servers (snapshots, row locks, DBAL 3 and 4) are covered by ConcurrentInstallMySqlTest / ConcurrentInstallPostgreSqlTest.
 */
final class ConcurrentInstallAsCommittedReadTest extends TestCase
{
    private const TEMPLATE  = 'race_template';
    private const SHOW      = 'SHOW SESSION VARIABLES';
    private const LOCK_READ = ' LOCK IN SHARE MODE';

    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'wfyp');
        $manager    = IntegrationEntityManagerFactory::createForParams(RaceHarness::sqliteParams($this->path));
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $template = new WorkflowDefinition('Race template', self::TEMPLATE, 'draft', 'App\\Entity\\Race');
        $template->addPlace(new WorkflowPlace('draft', null, 0));
        $template->addPlace(new WorkflowPlace('review', null, 1));
        $template->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        $template->publish();
        $manager->persist($template);
        $manager->flush();
        // The winner: an install committed by another connection.
        self::assertTrue($this->catalog($manager)->installTemplate(self::TEMPLATE, new WorkflowTenant('org-a'))->created);
        $manager->getConnection()->close();
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function onMySqlRepeatableReadTheLoserReadsTheWinnerWithALockingReadInsideTheHostsTransaction(): void
    {
        $locking = $this->server(new MySQL80Platform(), ['transaction_isolation' => 'REPEATABLE-READ']);
        $result  = $this->loseInsideTheHostsTransaction($locking);

        self::assertFalse($result['created']);
        self::assertSame(['draft', 'review'], $result['places'], 'places and transitions come from the same locking read');
        self::assertSame(['submit'], $result['transitions']);
        $locks = $this->lockingReads($locking);
        self::assertCount(1, $locks);
        self::assertStringContainsString(' LEFT JOIN ', $locks[0]);
    }

    /**
     * Audit YS: the server's own session variables decide, never the platform class (a MariaDB host configured with a
     * MySQL `serverVersion` reports MySQL80Platform). A variable the server lacks is simply not listed.
     *
     * @param array<string, string> $variables
     */
    #[Test]
    #[DataProvider('sessions')]
    public function theSessionDecidesWhetherTheLockingReadIsUsed(AbstractMySQLPlatform $platform, array $variables, bool $locks): void
    {
        $locking = $this->server($platform, $variables);
        $result  = $this->loseInsideTheHostsTransaction($locking);

        self::assertFalse($result['created'], 'the loser answers the winner either way (SQLite has no snapshot)');
        self::assertCount($locks ? 1 : 0, $this->lockingReads($locking));
        self::assertNotSame([], array_filter($locking->statements, static fn (string $sql): bool => str_starts_with($sql, self::SHOW)));
    }

    /**
     * @return iterable<string, array{AbstractMySQLPlatform, array<string, string>, bool}>
     */
    public static function sessions(): iterable
    {
        yield 'MySQL without the snapshot variable' => [new MySQL80Platform(), ['transaction_isolation' => 'REPEATABLE-READ'], true];
        yield 'MySQL SERIALIZABLE' => [new MySQL80Platform(), ['transaction_isolation' => 'SERIALIZABLE'], true];
        yield 'MySQL READ COMMITTED: plain read, no lock' => [new MySQL80Platform(), ['transaction_isolation' => 'READ-COMMITTED'], false];
        yield 'READ UNCOMMITTED: plain read' => [new MySQL80Platform(), ['transaction_isolation' => 'READ-UNCOMMITTED'], false];
        yield 'old MariaDB tx_isolation READ COMMITTED' => [new MariaDBPlatform(), ['tx_isolation' => 'READ-COMMITTED'], false];
        yield 'old MariaDB without the snapshot variable' => [new MariaDBPlatform(), ['tx_isolation' => 'REPEATABLE-READ'], true];
        yield 'MariaDB snapshot isolation OFF' => [new MariaDBPlatform(), ['transaction_isolation' => 'REPEATABLE-READ', 'innodb_snapshot_isolation' => 'OFF'], true];
        yield 'MariaDB snapshot isolation ON' => [new MariaDBPlatform(), ['transaction_isolation' => 'REPEATABLE-READ', 'innodb_snapshot_isolation' => 'ON'], false];
        yield 'MariaDB behind a MySQL serverVersion, snapshot isolation ON' => [new MySQL80Platform(), ['transaction_isolation' => 'REPEATABLE-READ', 'innodb_snapshot_isolation' => 'ON'], false];
        yield 'no variable listed at all' => [new MySQL80Platform(), [], true];
    }

    #[Test]
    public function onOtherPlatformsTheLoserInsideTheHostsTransactionUsesThePlainRead(): void
    {
        // SQLite (one writer) and PostgreSQL READ COMMITTED: the plain read sees the committed winner.
        $manager = IntegrationEntityManagerFactory::createForParams(RaceHarness::sqliteParams($this->path));
        $host    = $manager->getConnection();
        $host->beginTransaction();
        $result = $this->loserCatalog($manager)->installTemplate(self::TEMPLATE, new WorkflowTenant('org-a'));

        self::assertFalse($result->created);
        self::assertSame(1, $host->getTransactionNestingLevel());
        self::assertFalse($host->isRollbackOnly());
        $host->commit();
        $host->close();
    }

    /**
     * The loser (plain reads held stale, as under a REPEATABLE READ snapshot) inside the host's transaction; the host
     * commits afterwards.
     *
     * @return array{created: bool, places: list<string>, transitions: list<string>}
     */
    private function loseInsideTheHostsTransaction(LockingSqlMiddleware $locking): array
    {
        $manager = $this->mysqlManager($locking);
        $host    = $manager->getConnection();
        $host->beginTransaction();
        $result = $this->loserCatalog($manager)->installTemplate(self::TEMPLATE, new WorkflowTenant('org-a'));

        self::assertTrue($host->isTransactionActive(), 'the bundle never ends the host\'s transaction');
        self::assertSame(1, $host->getTransactionNestingLevel());
        self::assertFalse($host->isRollbackOnly());
        $host->commit();
        self::assertSame(1, (int) $host->fetchOne('SELECT COUNT(*) FROM workflow_definition WHERE slug = ? AND tenant_key = ?', [self::TEMPLATE, 'org-a']));
        $host->close();

        return ['created' => $result->created, 'places' => $result->definition->placeNames, 'transitions' => $result->definition->transitionNames];
    }

    /**
     * A MySQL-family server whose session variables are $variables (the SHOW statement is answered by SQLite).
     *
     * @param array<string, string> $variables
     */
    private function server(AbstractMySQLPlatform $platform, array $variables): LockingSqlMiddleware
    {
        $rows = [];
        foreach ($variables as $name => $value) {
            $rows[] = "SELECT '" . $name . "', '" . $value . "'";
        }

        return new LockingSqlMiddleware($platform, [self::SHOW => $rows === [] ? 'SELECT 1, 1 WHERE 0' : implode(' UNION ALL ', $rows)]);
    }

    /**
     * @return list<string>
     */
    private function lockingReads(LockingSqlMiddleware $locking): array
    {
        return array_values(array_filter($locking->statements, static fn (string $sql): bool => str_ends_with($sql, self::LOCK_READ) && str_contains($sql, 'workflow_definition')));
    }

    private function mysqlManager(LockingSqlMiddleware $locking): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setMiddlewares([$locking]);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(RaceHarness::sqliteParams($this->path), $config), $config);
    }

    private function loserCatalog(EntityManagerInterface $manager): WorkflowDefinitionCatalog
    {
        $repository = (new StaleReadDefinitionRepository(new TestManagerRegistry($manager)))->holdUntilCatchUp();

        return new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);
    }

    private function catalog(EntityManagerInterface $manager): WorkflowDefinitionCatalog
    {
        $repository = new StaleReadDefinitionRepository(new TestManagerRegistry($manager));
        $repository->catchUp();

        return new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);
    }
}
