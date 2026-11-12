<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ddd;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Tests\Integration\Ddd\Domain\PurchaseOrder;
use Nowo\WorkflowBundle\Tests\Integration\Ports\PortsKernelSupport;
use Nowo\WorkflowBundle\Tests\Integration\Ports\PortsTestKernel;
use Nowo\WorkflowBundle\Tests\Support\Access\PolicyPredicate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function method_exists;
use function sys_get_temp_dir;

/**
 * 1.14 DDD / hexagonal host (REQ-ARCH-001 "both host kinds are tested"): a plain-PHP aggregate mapped in XML by the
 * host, the bundle entities mapped from the shipped XML on a dedicated `workflow` entity manager (register_mapping:
 * false, doctrine.entity_manager: workflow), the host's framework-free policy on the 1.14 port, flush_after_apply:
 * false. The kernel boots, the definitions are persisted and read through the bundle, a transition is applied to the
 * aggregate and only the host's own flush persists it.
 */
final class DddHostKernelTest extends TestCase
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
    public function aDddHostRunsTheWorkflowWithoutTheBundleTouchingItsManager(): void
    {
        $kernel = new PortsTestKernel('ddd', false);
        $kernel->boot();
        try {
            $container = self::testContainer($kernel);
            self::createSchemas($kernel);

            $doctrine = $container->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $workflowManager = $doctrine->getManager('workflow');
            self::assertInstanceOf(EntityManagerInterface::class, $workflowManager);
            $driver = $workflowManager->getConfiguration()->getMetadataDriverImpl();
            // DoctrineBundle wraps the chain in its own MappingDriver.
            self::assertNotNull($driver);
            $driver = method_exists($driver, 'getDriver') ? $driver->getDriver() : $driver;
            self::assertInstanceOf(MappingDriverChain::class, $driver);
            self::assertInstanceOf(SimplifiedXmlDriver::class, $driver->getDrivers()['Nowo\\WorkflowBundle\\Entity'] ?? null, 'the shipped XML mapping');
            self::assertSame($workflowManager, $doctrine->getManagerForClass(WorkflowDefinition::class));
            self::assertSame($doctrine->getManager('default'), $doctrine->getManagerForClass(PurchaseOrder::class));

            $definition = self::seedPurchaseOrderFlow($kernel);
            $private    = self::definitionsManager($kernel);
            self::assertSame($workflowManager->getConnection(), $private->getConnection(), 'the private manager uses the dedicated connection');
            self::assertSame(0, $workflowManager->getUnitOfWork()->size(), 'the dedicated manager itself is never written');

            $repository = $container->get(WorkflowDefinitionRepository::class);
            self::assertInstanceOf(WorkflowDefinitionRepository::class, $repository);
            self::assertSame($definition->getId(), $repository->findOneBySlug('purchase_order_flow')?->getId());

            $resolver = $container->get('test.' . WorkflowResolver::class);
            self::assertInstanceOf(WorkflowResolver::class, $resolver);
            $order = new PurchaseOrder('PO-DDD');
            self::assertSame('purchase_order_flow', $resolver->resolveSlugForSubject($order));

            $host = self::hostManager($kernel);
            $host->persist($order);
            $host->flush();

            $applicator = $container->get('test.' . WorkflowApplicator::class);
            self::assertInstanceOf(WorkflowApplicator::class, $applicator);
            $applicator->applyForSubject($order, 'submit');
            self::assertSame('draft', $host->getConnection()->fetchOne('SELECT status FROM host_purchase_order'), 'flush_after_apply: false');
            $host->flush();
            self::assertSame('submitted', $host->getConnection()->fetchOne('SELECT status FROM host_purchase_order'));
            self::assertSame(['approve'], $applicator->getEnabledTransitionsForSubject($order));

            $policy = $container->get(WorkflowUiAccessPolicyInterface::class);
            self::assertInstanceOf(PolicyPredicate::class, $policy, 'the host policy on the 1.14 port wins');
            self::assertSame(200, self::send($kernel, '/workflow/definitions', 'admin')->getStatusCode());
            self::assertSame(403, self::send($kernel, '/workflow/definitions', 'admin-as-alice')->getStatusCode(), 'the host policy reads the impersonator from the VO');
        } finally {
            $kernel->shutdown();
        }
    }
}
