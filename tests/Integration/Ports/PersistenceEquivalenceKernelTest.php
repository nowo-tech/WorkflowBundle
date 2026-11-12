<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Tests\Integration\Ddd\Domain\PurchaseOrder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 1.14 persistence (REQ-ARCH-001 rule 6) in a booted standard host:
 *
 * - EM equivalence: applying a transition with `flush_after_apply: true` (the bundle flushes the application's
 *   manager, 1.13 behaviour) and with `flush_after_apply: false` followed by the application's own flush leave exactly
 *   the same database state (host rows and workflow tables); with false nothing is written before the host flushes;
 * - definition writes go through the private manager: a definition save never commits an application change left
 *   pending, and the application's manager never holds a definition row.
 */
final class PersistenceEquivalenceKernelTest extends TestCase
{
    use PortsKernelSupport;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    #[Test]
    public function applyLeavesTheSameDatabaseStateWithAndWithoutFlushAfterApply(): void
    {
        $flushed = $this->scenario(true);
        $manual  = $this->scenario(false);

        self::assertSame($flushed, $manual);
        self::assertSame([['reference' => 'PO-1', 'status' => 'submitted'], ['reference' => 'PO-2', 'status' => 'draft']], $flushed['host_purchase_order']);
        self::assertCount(1, $flushed['workflow_definition']);
        self::assertCount(3, $flushed['workflow_place']);
        self::assertCount(2, $flushed['workflow_transition']);
    }

    #[Test]
    public function aDefinitionSaveNeverCommitsTheApplicationsPendingWork(): void
    {
        $kernel = new PortsTestKernel('builtin', false);
        $kernel->boot();
        try {
            self::createSchemas($kernel);
            $host = self::hostManager($kernel);
            $host->persist(new PurchaseOrder('PENDING'));

            self::seedPurchaseOrderFlow($kernel);
            $seeder = self::testContainer($kernel)->get('test.' . DemoSeedService::class);
            self::assertInstanceOf(DemoSeedService::class, $seeder);
            $seeder->seed();

            $connection = $host->getConnection();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_purchase_order'), 'the host order is still pending');
            self::assertGreaterThan(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_definition'));
            self::assertCount(1, $host->getUnitOfWork()->getScheduledEntityInsertions(), 'the application manager holds only its own pending order');
            self::assertNotSame($host, self::definitionsManager($kernel)->live());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function scenario(bool $flushAfterApply): array
    {
        $kernel = new PortsTestKernel('builtin', false, $flushAfterApply);
        $kernel->boot();
        try {
            self::createSchemas($kernel);
            self::seedPurchaseOrderFlow($kernel);
            $host  = self::hostManager($kernel);
            $order = new PurchaseOrder('PO-1');
            $host->persist($order);
            $host->flush();
            // A change the application left pending when it applies the transition.
            $host->persist(new PurchaseOrder('PO-2'));

            $applicator = self::testContainer($kernel)->get('test.' . WorkflowApplicator::class);
            if (!$applicator instanceof WorkflowApplicator) {
                throw new RuntimeException('No applicator.');
            }
            $applicator->apply($order, 'purchase_order_flow', 'submit');
            self::assertSame('submitted', $order->getStatus());

            if (!$flushAfterApply) {
                $before = $this->snapshot($host);
                self::assertSame([['reference' => 'PO-1', 'status' => 'draft']], $before['host_purchase_order'], 'false: the bundle wrote nothing');
                $host->flush();
            }

            return $this->snapshot($host);
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(EntityManagerInterface $host): array
    {
        $connection = $host->getConnection();

        return [
            'host_purchase_order' => $connection->fetchAllAssociative('SELECT reference, status FROM host_purchase_order ORDER BY reference'),
            'workflow_definition' => $connection->fetchAllAssociative('SELECT slug, name, initial_place, subject_class, enabled FROM workflow_definition ORDER BY slug'),
            'workflow_place'      => $connection->fetchAllAssociative('SELECT name, sort_order FROM workflow_place ORDER BY name'),
            'workflow_transition' => $connection->fetchAllAssociative('SELECT name, from_places, to_places FROM workflow_transition ORDER BY name'),
        ];
    }
}
