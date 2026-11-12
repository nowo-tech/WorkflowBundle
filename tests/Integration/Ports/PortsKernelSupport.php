<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Integration\Ddd\Domain\PurchaseOrder;
use Nowo\WorkflowBundle\Tests\Support\Access\TestTokenListener;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Helpers of the {@see PortsTestKernel} tests: schema creation and requests sent straight to `http_kernel` (never
 * Kernel::handle(), which would run the services resetter between requests).
 */
trait PortsKernelSupport
{
    private static function testContainer(PortsTestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException('No test container.');
        }

        return $container;
    }

    private static function createSchemas(PortsTestKernel $kernel): void
    {
        $doctrine = self::testContainer($kernel)->get('doctrine');
        if (!$doctrine instanceof ManagerRegistry) {
            throw new RuntimeException('No Doctrine registry.');
        }

        foreach ($doctrine->getManagers() as $manager) {
            if ($manager instanceof EntityManagerInterface) {
                (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
            }
        }

        if (!$doctrine->getManagerForClass(WorkflowDefinition::class) instanceof EntityManagerInterface) {
            throw new RuntimeException('The workflow entities are not mapped.');
        }
    }

    /**
     * Seeds the `purchase_order_flow` definition (draft → submitted → approved) through the bundle's repository
     * manager (the private entity manager in 1.14), the way the admin UI saves a definition.
     */
    private static function seedPurchaseOrderFlow(PortsTestKernel $kernel, string $slug = 'purchase_order_flow'): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Purchase orders', $slug, 'draft', PurchaseOrder::class);
        foreach (['draft', 'submitted', 'approved'] as $index => $place) {
            $definition->addPlace(new WorkflowPlace($place, null, $index));
        }
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['submitted']));
        $definition->addTransition(new WorkflowTransition('approve', ['submitted'], ['approved']));
        self::repository($kernel)->save($definition);

        return $definition;
    }

    private static function repository(PortsTestKernel $kernel): WorkflowDefinitionRepository
    {
        $repository = self::testContainer($kernel)->get(WorkflowDefinitionRepository::class);
        if (!$repository instanceof WorkflowDefinitionRepository) {
            throw new RuntimeException('No repository.');
        }

        return $repository;
    }

    /**
     * The bundle's private entity manager (the manager of the repository in 1.14).
     */
    private static function definitionsManager(PortsTestKernel $kernel): WorkflowEntityManager
    {
        $manager = self::testContainer($kernel)->get(WorkflowEntityManager::class);
        if (!$manager instanceof WorkflowEntityManager) {
            throw new RuntimeException('No private entity manager.');
        }

        return $manager;
    }

    /**
     * The application's own default manager (never written by the bundle in 1.14).
     */
    private static function hostManager(PortsTestKernel $kernel): EntityManagerInterface
    {
        $doctrine = self::testContainer($kernel)->get('doctrine');
        $manager  = $doctrine instanceof ManagerRegistry ? $doctrine->getManager() : null;
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('No default manager.');
        }

        return $manager;
    }

    private static function send(PortsTestKernel $kernel, string $path, ?string $user, string $method = 'GET'): Response
    {
        $httpKernel = self::testContainer($kernel)->get('http_kernel');
        if (!$httpKernel instanceof HttpKernelInterface) {
            throw new RuntimeException('No http_kernel.');
        }

        $request = Request::create($path, $method);
        if ($user !== null) {
            $request->headers->set(TestTokenListener::HEADER, $user);
        }
        $response = $httpKernel->handle($request);
        if ($httpKernel instanceof TerminableInterface) {
            $httpKernel->terminate($request, $response);
        }

        return $response;
    }
}
