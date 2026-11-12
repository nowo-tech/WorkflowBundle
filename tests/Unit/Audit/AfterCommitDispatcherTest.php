<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Audit;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Audit\AuditConnection;
use Nowo\WorkflowBundle\Audit\AuditMiddleware;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * After-commit dispatch of the audit event: transactions, savepoints, failed commits, failing listeners, boundaries.
 */
final class AfterCommitDispatcherTest extends TestCase
{
    #[Test]
    public function savepointsKeepOrDropTheirEvents(): void
    {
        [$dispatcher, $seen] = $this->dispatcher();
        $connection          = new stdClass();
        $one                 = new stdClass();
        $two                 = new stdClass();
        $three               = new stdClass();

        $dispatcher->savepointCreated($connection, 'ignored'); // no transaction: nothing
        $dispatcher->transactionStarted($connection);
        $dispatcher->record($one);
        $dispatcher->savepointCreated($connection, 'SP1');
        $dispatcher->record($two);
        $dispatcher->savepointRolledBack($connection, 'sp1');
        $dispatcher->savepointCreated($connection, 'SP2');
        $dispatcher->record($three);
        $dispatcher->savepointReleased($connection, 'SP2');
        $dispatcher->savepointReleased($connection, 'unknown');
        self::assertSame(2, $dispatcher->countPending());
        self::assertSame(0, $dispatcher->dispatchPending(), 'a transaction is open');

        $dispatcher->transactionEnded($connection, true);
        $dispatcher->transactionStarted($other = new stdClass());
        self::assertSame(0, $dispatcher->dispatchPending(), 'committed, but another transaction is open');
        $dispatcher->transactionEnded($other, true);
        $dispatcher->dispatchPending();
        self::assertSame([$one, $three], $seen->events);
    }

    #[Test]
    public function aRollbackOrAFailedCommitDropsTheEvents(): void
    {
        [$dispatcher, $seen] = $this->dispatcher();
        $connection          = new stdClass();
        $dispatcher->record(new stdClass());
        $dispatcher->transactionStarted($connection);
        $dispatcher->transactionEnded($connection, false);
        $dispatcher->onBoundary();
        self::assertSame([], $seen->events);
        self::assertSame(0, $dispatcher->countPending());
    }

    #[Test]
    public function aFailingListenerIsLoggedAndTheOthersStillRun(): void
    {
        $logger     = new RecordingLogger();
        $events     = new EventDispatcher();
        $dispatched = [];
        $events->addListener(stdClass::class, static function () use (&$dispatched): never {
            $dispatched[] = 'first';

            throw new RuntimeException('listener');
        });
        $dispatcher = new AfterCommitDispatcher($events, $logger);
        $connection = new stdClass();
        $dispatcher->transactionStarted($connection);
        $dispatcher->record(new stdClass());
        $dispatcher->record(new stdClass());
        $dispatcher->transactionEnded($connection, true);

        self::assertSame(2, $dispatcher->dispatchPending());
        self::assertSame(['first', 'first'], $dispatched);
        self::assertSame('error', $logger->records[0]['level']);

        $dispatcher->record(new stdClass());
        $dispatcher->reset();
        self::assertSame(0, $dispatcher->countPending());
        self::assertArrayHasKey('console.terminate', AfterCommitDispatcher::getSubscribedEvents());
    }

    #[Test]
    public function theConnectionReportsItsTransactions(): void
    {
        [$dispatcher, $seen] = $this->dispatcher();
        $inner               = $this->createMock(Connection::class);
        $inner->method('exec')->willReturn(0);
        $inner->expects(self::exactly(2))->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit')));
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);
        $connection = (new AuditMiddleware($dispatcher))->wrap($driver)->connect([]);
        self::assertInstanceOf(AuditConnection::class, $connection);

        $connection->beginTransaction();
        $dispatcher->record($kept = new stdClass());
        $connection->exec('SAVEPOINT SP1');
        $dispatcher->record(new stdClass());
        $connection->exec('ROLLBACK TO SAVEPOINT SP1');
        $connection->exec('SAVEPOINT SP2');
        $connection->exec('RELEASE SAVEPOINT SP2');
        $connection->exec('SELECT 1');
        $connection->commit();
        $dispatcher->dispatchPending();
        self::assertSame([$kept], $seen->events);

        $connection->beginTransaction();
        $dispatcher->record(new stdClass());
        try {
            $connection->commit();
            self::fail('The commit fails.');
        } catch (RuntimeException) {
        }
        $connection->beginTransaction();
        $dispatcher->record(new stdClass());
        $connection->rollBack();
        $dispatcher->dispatchPending();
        self::assertSame([$kept], $seen->events, 'failed commit and rollback dropped');
    }

    /**
     * @return array{AfterCommitDispatcher, object{events: list<object>}}
     */
    private function dispatcher(): array
    {
        $seen = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $events = new EventDispatcher();
        $events->addListener(stdClass::class, static function (object $event) use ($seen): void {
            $seen->events[] = $event;
        });

        return [new AfterCommitDispatcher($events, new RecordingLogger()), $seen];
    }
}
