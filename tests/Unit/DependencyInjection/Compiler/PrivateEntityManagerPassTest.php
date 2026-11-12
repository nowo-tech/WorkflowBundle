<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class PrivateEntityManagerPassTest extends TestCase
{
    public function testAttachesTheMiddlewareToTheSourceManagerConnectionOnly(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([['connection' => 'app']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'primary');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'primary']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container = $this->container();
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'default']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testDoesNothingWithoutThePrivateManager(): void
    {
        $container = new ContainerBuilder();
        (new PrivateEntityManagerPass())->process($container);
        self::assertFalse($container->hasDefinition(PrivateEntityManagerMiddleware::class));
        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware(), 'doctrine/dbal 4 in the test suite.');
    }

    public function testTheConfiguredManagerDecidesTheConnection(): void
    {
        $container = $this->container();
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'workflow');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'workflow' => 'doctrine.orm.workflow_entity_manager']);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition('doctrine.orm.workflow_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.wf_connection')]));

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([['connection' => 'wf']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testAnUnknownConfiguredManagerFailsTheBuild(): void
    {
        $container = $this->container();
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'wrokflow');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"wrokflow"');

        (new PrivateEntityManagerPass())->process($container);
    }

    public function testAnUnknownManagerFailsAlsoWithoutDoctrineParameters(): void
    {
        $container = $this->container();
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'workflow');
        $container->setParameter('doctrine.entity_managers', 'not-a-map');

        $this->expectException(InvalidConfigurationException::class);

        (new PrivateEntityManagerPass())->process($container);
    }

    public function testWithoutTheMiddlewareOnlyTheManagerIsChecked(): void
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowEntityManager::class);
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'default');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        (new PrivateEntityManagerPass())->process($container);

        self::assertFalse($container->hasDefinition(PrivateEntityManagerMiddleware::class));
    }

    public function testTheSourceManagerMustMapTheEntities(): void
    {
        $container = $this->container();
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))
            ->addMethodCall('setDefaultDriver', [new Reference('x')])
            ->addMethodCall('addDriver', [new Reference('app'), 'App\\Entity']));

        try {
            (new PrivateEntityManagerPass())->process($container);
            self::fail('The default manager does not map the bundle entities.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('"default"', $exception->getMessage());
        }

        foreach (['Nowo\\WorkflowBundle\\Entity', 'Nowo\\WorkflowBundle', 'Nowo\\WorkflowBundle\\Entity\\'] as $namespace) {
            $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('addDriver', [new Reference('wf'), $namespace]);
            (new PrivateEntityManagerPass())->process($container);
            self::assertSame([['connection' => 'default']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
        }
    }

    public function testANamespaceThatOnlySharesAPrefixDoesNotCount(): void
    {
        $container = $this->container();
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))
            ->addMethodCall('addDriver', [new Reference('wf'), 'Nowo\\WorkflowBundle\\EntityExtra']));

        $this->expectException(InvalidConfigurationException::class);
        (new PrivateEntityManagerPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowEntityManager::class);
        $container->register(PrivateEntityManagerMiddleware::class)->addTag('doctrine.middleware');

        return $container;
    }
}
