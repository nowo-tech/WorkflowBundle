<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\WorkflowBundle\Controller\WorkflowDefinitionController;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Doctrine\WorkflowManagerRegistry;
use Nowo\WorkflowBundle\EventListener\WorkflowUnitOfWorkSubscriber;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.14: private entity manager wiring, the flush_after_apply deprecation and the XML mapping option.
 */
final class NowoWorkflowExtensionPersistenceTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        $this->deprecations = [];
        // Test-only handler, restored in tearDown().
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(function (int $level, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    #[Test]
    public function theDefinitionsGoThroughThePrivateManagerByDefault(): void
    {
        $container = $this->load(['runtime' => ['flush_after_apply' => false]]);

        self::assertTrue($container->hasDefinition(WorkflowEntityManager::class));
        self::assertSame([['method' => 'reset']], $container->getDefinition(WorkflowEntityManager::class)->getTag('kernel.reset'));
        self::assertNull($container->getParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER));
        self::assertEquals(new Reference(WorkflowManagerRegistry::class), $container->getDefinition(WorkflowDefinitionRepository::class)->getArgument('$registry'));
        $controller = $container->getDefinition(WorkflowDefinitionController::class);
        self::assertEquals(new Reference(WorkflowEntityManager::class), $controller->getArgument('$entityManager'));
        self::assertNull($controller->getArgument('$managerRegistry'));
        self::assertEquals(new Reference(WorkflowEntityManager::class), $container->getDefinition(DemoSeedService::class)->getArgument('$entityManager'));
        self::assertTrue($container->hasDefinition(PrivateEntityManagerMiddleware::class));
        $resettables = $container->getDefinition(WorkflowUnitOfWorkSubscriber::class)->getArgument('$resettables');
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertCount(1, $resettables->getValues());
        self::assertSame([], $this->deprecations, 'flush_after_apply set explicitly: no deprecation');
    }

    #[Test]
    public function theDeprecatedOptOutKeepsThe113Wiring(): void
    {
        $container = $this->load(['runtime' => ['flush_after_apply' => true], 'doctrine' => ['private_entity_manager' => false, 'entity_manager' => 'workflow']]);

        self::assertFalse($container->hasDefinition(WorkflowEntityManager::class));
        self::assertFalse($container->hasDefinition(WorkflowManagerRegistry::class));
        self::assertSame('workflow', $container->getParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER));
        self::assertSame([], $container->getDefinition(WorkflowDefinitionRepository::class)->getArguments());
    }

    #[Test]
    public function notSettingFlushAfterApplyKeepsTrueWithADeprecation(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter('nowo_workflow.runtime.flush_after_apply'));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('flush_after_apply', $this->deprecations[0]);
    }

    #[Test]
    public function aLaterFileSettingFlushAfterApplyCounts(): void
    {
        $container = new ContainerBuilder();
        (new NowoWorkflowExtension())->load([['web_ui' => ['enabled' => true]], ['runtime' => []], [], ['runtime' => ['flush_after_apply' => true]]], $container);

        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function theXmlMappingCanReplaceTheAttributes(): void
    {
        $container = $this->prepended([['doctrine' => ['mapping_type' => 'xml']], ['doctrine' => 'invalid'], ['doctrine' => ['mapping_type' => 'yaml']]]);
        $mapping   = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['NowoWorkflowBundle'] ?? null;

        self::assertIsArray($mapping);
        self::assertSame('xml', $mapping['type']);
        self::assertSame(NowoWorkflowExtension::XML_MAPPING_DIR, $mapping['dir']);
        self::assertFileExists(NowoWorkflowExtension::XML_MAPPING_DIR . '/WorkflowDefinition.orm.xml');
    }

    #[Test]
    public function theHostCanMapTheEntitiesItself(): void
    {
        $container = $this->prepended([['doctrine' => ['register_mapping' => false]]]);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function theAttributeMappingStaysTheDefault(): void
    {
        $container = $this->prepended([]);
        $mapping   = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['NowoWorkflowBundle'] ?? null;

        self::assertIsArray($mapping);
        self::assertSame('attribute', $mapping['type']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoWorkflowExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @param list<mixed> $configs
     */
    private function prepended(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new NowoWorkflowExtension();
        $container->registerExtension($extension);
        $container->registerExtension(new DoctrineExtension());
        foreach ($configs as $config) {
            $container->loadFromExtension('nowo_workflow', $config);
        }
        $extension->prepend($container);

        return $container;
    }
}
