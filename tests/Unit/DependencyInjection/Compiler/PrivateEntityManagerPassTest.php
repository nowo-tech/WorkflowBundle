<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\RegisterMappingsPass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_DEPRECATED;

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
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))
            ->addMethodCall('setDefaultDriver', [new Reference('x')])
            ->addMethodCall('addDriver', [new Reference('app'), 'App\\Entity']));

        try {
            (new PrivateEntityManagerPass())->process($container);
            self::fail('No manager maps the bundle entities.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('"default"', $exception->getMessage());
        }

        foreach (['Nowo\\WorkflowBundle\\Entity', 'Nowo\\WorkflowBundle', 'Nowo\\WorkflowBundle\\Entity\\'] as $namespace) {
            $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('addDriver', [new Reference('wf'), $namespace]);
            (new PrivateEntityManagerPass())->process($container);
            self::assertSame([['connection' => 'default']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
            self::assertSame('default', $container->getDefinition(WorkflowEntityManager::class)->getArgument(1));
        }
    }

    public function testANamespaceThatOnlySharesAPrefixDoesNotCount(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))
            ->addMethodCall('addDriver', [new Reference('wf'), 'Nowo\\WorkflowBundle\\EntityExtra']));

        $this->expectException(InvalidConfigurationException::class);
        (new PrivateEntityManagerPass())->process($container);
    }

    /**
     * NF2-1 scenario A: the application's default manager is "main"; the bundle mapping (1.13: top-level
     * doctrine.orm.mappings) is on a manager named "default". 1.13 used it through getManagerForClass(): so does 1.14.
     */
    public function testScenarioAKeepsTheManagerThatMapsTheEntities(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager', 'default' => 'doctrine.orm.default_entity_manager']);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_metadata_driver', (new Definition('Chain'))->addMethodCall('addDriver', [new Reference('app'), 'App\\Entity']));
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))->addMethodCall('addDriver', [new Reference('wf'), 'Nowo\\WorkflowBundle\\Entity']));
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.legacy_connection')]));

        $deprecations = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            (new PrivateEntityManagerPass())->process($container);
        } finally {
            restore_error_handler();
        }

        self::assertSame('default', $container->getDefinition(WorkflowEntityManager::class)->getArgument(1));
        self::assertSame([['connection' => 'legacy']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('entity_manager: default', $deprecations[0]);
    }

    /**
     * NF2-1 scenario B: the host mapped the bundle on another manager: that one is used, never the default.
     */
    public function testScenarioBUsesTheManagerTheHostMappedTheEntitiesOn(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'workflow' => 'doctrine.orm.workflow_entity_manager']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', new Definition('Chain'));
        $container->setDefinition('doctrine.orm.workflow_metadata_driver', (new Definition('Chain'))->addMethodCall('addDriver', [new Reference('wf'), 'Nowo\\WorkflowBundle\\Entity']));
        $container->setDefinition('doctrine.orm.workflow_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.wf_connection')]));

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame('workflow', $container->getDefinition(WorkflowEntityManager::class)->getArgument(1));
        self::assertSame([['connection' => 'wf']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testAChainThatCannotBeInspectedLeavesTheRuntimeRule(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([], $container->getDefinition(WorkflowEntityManager::class)->getArguments(), 'getManagerForClass() at runtime');
        self::assertSame([['connection' => 'default']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    /**
     * Audit NF3-1: when a chain cannot be inspected, getManagerForClass() may pick any manager not proven to not map
     * the entities (up to the first one that maps them): the middleware is on each of their connections, in order,
     * once per connection.
     */
    public function testTheRuntimeRuleGetsTheMiddlewareOnEveryCandidateConnection(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_entity_manager', 'a');
        $container->setParameter('doctrine.entity_managers', ['a' => 'x', 'b' => 'x', 'c' => 'x', 'd' => 'x', 'e' => 'x', 'f' => 'x']);
        $container->setDefinition('doctrine.orm.a_metadata_driver', new Definition('Chain'));
        $container->setDefinition('doctrine.orm.c_metadata_driver', (new Definition('Chain'))->addMethodCall('addDriver', [new Reference('app'), 'App\\Entity']));
        $container->setDefinition('doctrine.orm.e_metadata_driver', (new Definition('Chain'))->addMethodCall('addDriver', [new Reference('wf'), 'Nowo\\WorkflowBundle\\Entity']));
        foreach (['a' => 'conn_a', 'b' => 'conn_b', 'c' => 'conn_c', 'd' => 'conn_b', 'e' => 'conn_e', 'f' => 'conn_f'] as $manager => $connection) {
            $container->setDefinition(sprintf('doctrine.orm.%s_entity_manager', $manager), new Definition('EM', [new Reference(sprintf('doctrine.dbal.%s_connection', $connection))]));
        }

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([], $container->getDefinition(WorkflowEntityManager::class)->getArguments(), 'getManagerForClass() at runtime');
        self::assertSame([['connection' => 'conn_b'], ['connection' => 'conn_e']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    /**
     * Audit NF3-1: only uninspectable managers after the proven ones: each of their connections, the default
     * connection for a manager definition that does not name one.
     */
    public function testTheRuntimeRuleWithOnlyUninspectableManagers(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'primary');
        $container->setParameter('doctrine.entity_managers', ['a' => 'x', 'b' => 'x']);
        $container->setDefinition('doctrine.orm.b_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.other_connection')]));

        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([['connection' => 'primary'], ['connection' => 'other']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    /**
     * NF2-3: a host DoctrineOrmMappingsPass registered for later in the build counts (no false failure).
     */
    public function testPendingHostMappingPassesCount(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition('doctrine.orm.default_metadata_driver', new Definition('Chain'));
        // Ignored: disabled, unresolvable manager, another Doctrine flavour.
        $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver([__DIR__ => 'Nowo\\WorkflowBundle\\Entity'], [], 'app.mapping_disabled'));
        $container->addCompilerPass(new class(new Definition('X'), ['Nowo\\WorkflowBundle\\Entity'], ['app.no_such_manager'], 'doctrine.orm.%s_metadata_driver') extends RegisterMappingsPass {
        });
        $container->addCompilerPass(new class(new Definition('X'), ['Nowo\\WorkflowBundle\\Entity'], ['doctrine.default_entity_manager'], 'doctrine_mongodb.odm.%s_metadata_driver') extends RegisterMappingsPass {
        });

        try {
            (new PrivateEntityManagerPass())->process($container);
            self::fail('Nothing maps the entities yet.');
        } catch (InvalidConfigurationException) {
        }

        $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver([__DIR__ => 'App\\Entity', __DIR__ . '/x' => 'Nowo\\WorkflowBundle\\Entity']));
        (new PrivateEntityManagerPass())->process($container);

        self::assertSame('default', $container->getDefinition(WorkflowEntityManager::class)->getArgument(1));
    }

    public function testAConfiguredManagerThatDoesNotMapTheEntitiesFails(): void
    {
        $container = $this->container();
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'workflow');
        $container->setParameter('doctrine.entity_managers', ['workflow' => 'doctrine.orm.workflow_entity_manager']);
        $container->setDefinition('doctrine.orm.workflow_metadata_driver', new Definition('Chain'));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"workflow"');
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
