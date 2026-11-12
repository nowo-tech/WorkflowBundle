<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Audit;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function array_slice;
use function count;
use function strcasecmp;

/**
 * Dispatches the audit events of applied transitions after the commit that persisted them (since 1.15,
 * REQ-ARCH-001 rule 7; the LegalKit / InstanceSettings after-commit design).
 *
 * - An event recorded inside a transaction is held by its innermost level (savepoint): a rollback drops it, the
 *   outermost commit marks it committed.
 * - An event recorded with no transaction open (the marking only changed in memory) waits for the next transaction
 *   that begins on a connection, i.e. the flush that persists the subject; it then follows that transaction. If no
 *   transaction begins before the end of the request, console command or Messenger message, the marking was never
 *   persisted: the event is dropped and a warning is logged.
 * - Committed events are dispatched at the next safe point where no transaction is open: right after
 *   `WorkflowApplicator` flushed, and at the end of the request, console command or Messenger message (not from a
 *   Doctrine `postFlush`, where an application listener may still be inside its own unit of work, audit NO M4). Never
 *   from inside a driver commit. A listener that throws is logged; the other events are still dispatched.
 * - At the end of the request, console command or Messenger message, anything not committed (not persisted, or held
 *   by a transaction still open) is dropped with a warning, never carried over (audit NO L1).
 * - Only the transactions of the connections of the definitions' manager and of the application's default manager
 *   (where `WorkflowApplicator` persists the subject) are observed.
 *
 * Worker-safe: the state is keyed weakly by the driver connection, everything is either dispatched or dropped at each
 * boundary, and `kernel.reset` drops it all.
 *
 * @internal
 */
final class AfterCommitDispatcher implements TransactionObserverInterface, EventSubscriberInterface, ResetInterface
{
    /** @var WeakMap<object, list<array{0: string|null, 1: list<object>}>> */
    private WeakMap $transactions;

    /** @var list<object> */
    // @igor-ignore - Audit events of a marking change not persisted yet; dispatched or dropped at the boundary, kernel.reset.
    private array $awaiting = [];

    /** @var list<object> */
    // @igor-ignore - Committed audit events, dispatched at the next safe point (end of request at the latest), kernel.reset.
    private array $committed = [];

    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->transactions = new WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE                                           => ['onBoundary', -1024],
            'console.terminate'                                               => ['onBoundary', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent' => ['onBoundary', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'  => ['onBoundary', -1024],
        ];
    }

    /**
     * Records an event: held until its transaction commits (see the class comment).
     */
    public function record(object $event): void
    {
        $connection = null;
        foreach ($this->transactions as $open => $ignored) {
            $connection = $open;
        }
        if ($connection === null) {
            $this->awaiting[] = $event;

            return;
        }

        $levels        = $this->transactions[$connection];
        $last          = count($levels) - 1;
        $levels[$last] = [$levels[$last][0], [...$levels[$last][1], $event]];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    /**
     * Dispatches the committed events when no transaction is open; returns how many were dispatched.
     */
    public function dispatchPending(): int
    {
        $count = 0;
        while ($this->committed !== [] && $this->safe()) {
            $events          = $this->committed;
            $this->committed = [];
            foreach ($events as $event) {
                ++$count;
                try {
                    $this->dispatcher?->dispatch($event);
                } catch (Throwable $exception) {
                    $this->logger?->error('Workflow audit: a listener failed after the commit.', [
                        'bundle'    => 'NowoWorkflowBundle',
                        'event'     => $event::class,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * End of the request, console command or Messenger message: committed events are dispatched; events whose
     * marking change was never persisted are dropped.
     */
    public function onBoundary(): void
    {
        $this->dispatchPending();
        $dropped = $this->countPending();
        if ($dropped > 0) {
            // Never persisted, or held by a transaction still open at the end of the unit of work (a leaked transaction
            // that will never commit in this request / command / message): dropped, never carried over (audit NO L1).
            $this->logger?->warning('Workflow audit: {count} applied transition(s) were not committed by the end of the request, command or message; their audit events are dropped.', [
                'bundle' => 'NowoWorkflowBundle',
                'count'  => $dropped,
            ]);
        }
        $this->reset();
    }

    public function countPending(): int
    {
        $count = count($this->awaiting) + count($this->committed);
        foreach ($this->transactions as $levels) {
            $count += count($this->flatten($levels));
        }

        return $count;
    }

    public function transactionStarted(object $connection): void
    {
        // The events of a marking changed in memory follow the transaction that persists it.
        $held           = $this->awaiting;
        $this->awaiting = [];
        unset($this->transactions[$connection]);
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = [[null, $held]];
    }

    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        unset($this->transactions[$connection]);
        if ($committed) {
            $this->committed = [...$this->committed, ...$this->flatten($levels)];
        }
    }

    public function savepointCreated(object $connection, string $name): void
    {
        if (!isset($this->transactions[$connection])) {
            return;
        }
        $this->closeSavepoint($connection, $name, true);
        $levels   = $this->transactions[$connection];
        $levels[] = [$name, []];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, true);
    }

    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, false);
    }

    public function reset(): void
    {
        $this->transactions = new WeakMap();
        $this->awaiting     = [];
        $this->committed    = [];
    }

    private function safe(): bool
    {
        foreach ($this->transactions as $ignored) {
            return false;
        }

        return true;
    }

    private function closeSavepoint(object $connection, string $name, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        foreach ($levels as $index => [$level]) {
            if ($index > 0 && $level !== null && strcasecmp($level, $name) === 0) {
                $closed = $this->flatten(array_slice($levels, $index));
                $levels = array_slice($levels, 0, $index);
                if ($committed) {
                    $parent             = count($levels) - 1;
                    $levels[$parent][1] = [...$levels[$parent][1], ...$closed];
                }
                // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
                $this->transactions[$connection] = $levels;

                return;
            }
        }
    }

    /**
     * @param list<array{0: string|null, 1: list<object>}> $levels
     *
     * @return list<object>
     */
    private function flatten(array $levels): array
    {
        $events = [];
        foreach ($levels as [, $level]) {
            $events = [...$events, ...$level];
        }

        return $events;
    }
}
