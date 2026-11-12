<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Worker;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\EventListener\WorkflowUnitOfWorkSubscriber;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Integration\Ports\PortsKernelSupport;
use Nowo\WorkflowBundle\Tests\Integration\Ports\PortsTestKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\Event;

use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function sys_get_temp_dir;

/**
 * REQ-RUNTIME-002 (FrankenPHP worker mode, kernel reset false) for 1.14: ONE booted kernel serves N = 1,000 requests
 * through `http_kernel` (the services resetter never runs), alternating an administrator (granted) and a user
 * (denied) on the Workflow UI.
 *
 * - every response is the one of its user (no grant leaks to the next request);
 * - definitions renamed in the database by "another worker" are seen by the next request (the private entity manager
 *   is dropped at every main request: no stale identity map);
 * - a failed definition write that closes the private manager does not break the next request;
 * - the console and Messenger boundaries drop the private manager too;
 * - memory stays flat (growth below 2 MB after a 100-request warm-up).
 */
final class WorkerKeptAliveKernelTest extends TestCase
{
    use PortsKernelSupport;

    private const REQUESTS = 1000;

    private const WARM_UP = 100;

    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    #[Test]
    public function manyRequestsWithAlternatingUsersKeepDecisionsFreshAndMemoryFlat(): void
    {
        $kernel = new PortsTestKernel('builtin', false);
        $kernel->boot();
        try {
            self::createSchemas($kernel);
            self::seedPurchaseOrderFlow($kernel);
            $connection = self::hostManager($kernel)->getConnection();
            $name       = 'Purchase orders';
            $baseline   = 0;

            for ($i = 1; $i <= self::REQUESTS; ++$i) {
                if ($i % 100 === 0) {
                    // Another worker renames the definition: this worker must not serve the stale name.
                    $name = sprintf('Purchase orders rev %d', $i);
                    $connection->executeStatement('UPDATE workflow_definition SET name = ?', [$name]);
                }
                if ($i === 500) {
                    $this->closeThePrivateManager($kernel);
                }

                $admin = self::send($kernel, '/workflow/definitions', 'admin');
                self::assertSame(200, $admin->getStatusCode(), 'admin #' . $i);
                self::assertStringContainsString($name, (string) $admin->getContent(), 'fresh definition #' . $i);

                $alice = self::send($kernel, '/workflow/definitions', 'alice');
                self::assertSame(403, $alice->getStatusCode(), 'alice #' . $i);
                self::assertStringNotContainsString($name, (string) $alice->getContent());

                if ($i === self::WARM_UP) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
            }

            gc_collect_cycles();
            self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'memory growth after the warm-up');
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function consoleAndMessengerBoundariesDropThePrivateManager(): void
    {
        $kernel = new PortsTestKernel('builtin', false);
        $kernel->boot();
        try {
            self::createSchemas($kernel);
            self::seedPurchaseOrderFlow($kernel);
            $container  = self::testContainer($kernel);
            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $repository = $container->get(WorkflowDefinitionRepository::class);
            self::assertInstanceOf(WorkflowDefinitionRepository::class, $repository);
            $connection = self::hostManager($kernel)->getConnection();

            $command = new Command('app:test');
            $input   = new ArrayInput([]);
            $output  = new NullOutput();
            // symfony/messenger is not installed here: its worker events are dispatched by name (as subscribed).
            foreach ([
                [[new ConsoleCommandEvent($command, $input, $output), WorkflowUnitOfWorkSubscriber::CONSOLE_COMMAND], [new ConsoleTerminateEvent($command, $input, $output, 0), WorkflowUnitOfWorkSubscriber::CONSOLE_TERMINATE]],
                [[new Event(), WorkflowUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_RECEIVED], [new Event(), WorkflowUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_HANDLED]],
                [[new Event(), WorkflowUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_RECEIVED], [new Event(), WorkflowUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_FAILED]],
            ] as $round => [$start, $end]) {
                $dispatcher->dispatch(...$start);
                $loaded = $repository->findOneBySlug('purchase_order_flow');
                self::assertInstanceOf(WorkflowDefinition::class, $loaded);
                $connection->executeStatement('UPDATE workflow_definition SET name = ?', ['Renamed ' . $round]);
                $same = $repository->findOneBySlug('purchase_order_flow');
                self::assertSame($loaded, $same, 'inside one unit the identity map is kept');
                self::assertNotSame('Renamed ' . $round, $loaded->getName());
                $dispatcher->dispatch(...$end);

                $fresh = $repository->findOneBySlug('purchase_order_flow');
                self::assertInstanceOf(WorkflowDefinition::class, $fresh);
                self::assertSame('Renamed ' . $round, $fresh->getName(), 'the next unit reads fresh rows');
            }
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * A failed definition write (duplicate slug) closes the private manager; the bundle never resets an application
     * manager for it.
     */
    private function closeThePrivateManager(PortsTestKernel $kernel): void
    {
        $private = self::definitionsManager($kernel);
        $inner   = $private->live();
        $private->persist(new WorkflowDefinition('Duplicate', 'purchase_order_flow', 'draft', self::class));
        try {
            $private->flush();
            self::fail('The duplicate slug must fail.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($inner->isOpen(), 'the failed write closed the private manager');
        self::assertNotSame($inner, $private->live(), 'the next use gets a fresh private manager');
        self::assertTrue(self::hostManager($kernel)->isOpen(), 'the application manager stays open');
    }
}
