<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceSubject;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function dirname;
use function escapeshellarg;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_open;
use function sort;
use function stream_set_timeout;
use function trim;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 against a real server with real row locks: two connections race to apply a transition to one
 * subject, a held row lock makes the other apply a conflict, two processes race for real, and two writers race for the
 * same definition version number.
 */
abstract class AbstractConcurrentApplyRealDatabaseTestCase extends TestCase
{
    use ConcurrentApplyScenarios;

    protected string $url;

    protected EntityManagerInterface $a;

    protected EntityManagerInterface $b;

    abstract protected static function urlVariable(): string;

    /**
     * Makes the connection give up waiting for a row lock after about one second.
     */
    abstract protected function shortLockWait(EntityManagerInterface $manager): void;

    protected function setUp(): void
    {
        $url = getenv(static::urlVariable());
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(static::urlVariable() . ' is not set (real-database race tests).');
        }
        $this->url = $url;
        $this->a   = RaceHarness::entityManager(RaceHarness::urlParams($url));
        $this->b   = RaceHarness::entityManager(RaceHarness::urlParams($url));
        RaceHarness::createSchema($this->a);
    }

    protected function tearDown(): void
    {
        if (!isset($this->a)) {
            return;
        }
        $this->b->getConnection()->close();
        RaceHarness::dropSchema($this->a);
        $this->a->getConnection()->close();
    }

    #[Test]
    public function twoAppliesFromTheSamePlaceGiveOneTransitionAndTheLoserAConflict(): void
    {
        $id       = $this->subject();
        $subjectA = $this->a->find(RaceSubject::class, $id);
        $subjectB = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a)->apply($subjectA, RaceHarness::SLUG, 'submit');

        try {
            RaceHarness::applicator($this->b)->apply($subjectB, RaceHarness::SLUG, 'submit');
            self::fail('The second apply from the same place must lose.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_MARKING_CHANGED, $conflict->reason);
            self::assertSame(['review'], $conflict->currentPlaces);
        }

        self::assertSame('review', RaceHarness::storedStatus($this->b, $id));
        self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->b->getConnection()->fetchOne('SELECT 1'), 'the loser connection is still usable');
    }

    #[Test]
    public function aRowLockHeldByAnotherConnectionIsAConflictNotA500(): void
    {
        $id      = $this->subject();
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);
        $this->shortLockWait($this->b);

        $holder = $this->a->getConnection();
        $holder->beginTransaction();
        $holder->fetchOne('SELECT status FROM wfrt_race_subject WHERE id = ? FOR UPDATE', [$id]);

        try {
            RaceHarness::applicator($this->b)->apply($subject, RaceHarness::SLUG, 'submit');
            self::fail('A row lock held by another transaction must be a conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_LOCK, $conflict->reason);
            self::assertSame(1, $conflict->retryAfterSeconds());
            self::assertTrue($this->chainHas($conflict, TransientStorageFailure::class));
        } finally {
            $holder->rollBack();
        }

        self::assertSame('draft', RaceHarness::storedStatus($this->a, $id), 'nothing committed');
        self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());

        // The lock is gone: the same apply now goes through on the same connection.
        RaceHarness::applicator($this->b)->apply($subject, RaceHarness::SLUG, 'submit');
        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function aLockTimeoutInsideTheApplicationTransactionKeepsTheApplicationWork(): void
    {
        $id      = $this->subject();
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);
        $this->shortLockWait($this->b);

        $holder = $this->a->getConnection();
        $holder->beginTransaction();
        $holder->fetchOne('SELECT status FROM wfrt_race_subject WHERE id = ? FOR UPDATE', [$id]);

        $connection = $this->b->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO wfrt_race_subject (id, status, note) VALUES (900001, 'host', 'host work')");
        try {
            RaceHarness::applicator($this->b, flushAfterApply: false)->apply($subject, RaceHarness::SLUG, 'submit');
            self::fail('A row lock held by another transaction must be a conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_LOCK, $conflict->reason);
        } finally {
            $holder->rollBack();
        }
        // PostgreSQL: the failed locking read rolled back to the bundle's savepoint, not the whole transaction (25P02).
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        $connection->commit();

        self::assertSame(1, (int) $this->a->getConnection()->fetchOne("SELECT COUNT(*) FROM wfrt_race_subject WHERE note = 'host work'"));
    }

    #[Test]
    public function twoProcessesRacingGiveExactlyOneTransition(): void
    {
        $id     = $this->subject();
        $racers = [$this->racer($id), $this->racer($id)];
        foreach ($racers as [, $pipes]) {
            self::assertSame('ready', trim((string) fgets($pipes[1])));
        }
        // Both loaded the subject in `draft`: release them together.
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

        self::assertSame(['conflict:' . WorkflowConflictException::REASON_MARKING_CHANGED, 'ok'], $results);
        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function twoWritersRacingForTheNextDefinitionVersionCannotBothWinIt(): void
    {
        $tool     = new SchemaTool($this->bundleManager($this->url));
        $managerA = $this->bundleManager($this->url);
        $managerB = $this->bundleManager($this->url);
        $metadata = $managerA->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        try {
            $published = $this->definition();
            $published->publish();
            $managerA->persist($published);
            $managerA->flush();

            $repositoryA = new WorkflowDefinitionRepository(new TestManagerRegistry($managerA));
            $repositoryB = new WorkflowDefinitionRepository(new TestManagerRegistry($managerB));
            // Both read MAX(version)+1 before either writes it.
            $versionA = $repositoryA->nextVersion('race_versions', null);
            $versionB = $repositoryB->nextVersion('race_versions', null);
            self::assertSame(2, $versionA);
            self::assertSame(2, $versionB);

            $repositoryA->save($published->copyAsDraft(null, $versionA));
            $twin = $managerB->find(WorkflowDefinition::class, $published->getId());
            self::assertInstanceOf(WorkflowDefinition::class, $twin);
            try {
                $repositoryB->save($twin->copyAsDraft(null, $versionB));
                self::fail('The unique (tenant_key, slug, version) key must refuse the second version 2.');
            } catch (UniqueConstraintViolationException) {
                // The callers catch it (WorkflowVersioning::createDraftOf takes the winner's draft).
            }

            self::assertSame(2, (int) $managerA->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_definition WHERE slug = ?', ['race_versions']));
            self::assertSame(3, $repositoryA->nextVersion('race_versions', null));
        } finally {
            $tool->dropSchema($metadata);
            $managerA->getConnection()->close();
            $managerB->getConnection()->close();
        }
    }

    private function subject(): int
    {
        $subject = new RaceSubject('draft');
        $this->a->persist($subject);
        $this->a->flush();
        $this->a->clear();

        return (int) $subject->getId();
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function racer(int $id): array
    {
        $script = dirname(__DIR__, 2) . '/Support/Concurrency/apply-child.php';
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test-only CLI child; its reads are bounded below)
        $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->url) . ' ' . $id, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 30);

        return [$process, $pipes];
    }

    private function definition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Race versions', 'race_versions', 'draft', RaceSubject::class);
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('review', null, 1));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));

        return $definition;
    }

    private function bundleManager(string $url): EntityManagerInterface
    {
        return IntegrationEntityManagerFactory::createForParams(RaceHarness::urlParams($url));
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function chainHas(Throwable $failure, string $class): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
