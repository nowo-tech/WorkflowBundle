<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function sprintf;

/**
 * 2.0: a service the application still registers under, aliases to or decorates a removed port id fails the build
 * with a message naming the service and the port to use instead (REQ-ARCH-001, "a removed host-facing path fails
 * loudly").
 */
final class RemovedPortsPassTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function removedPorts(): iterable
    {
        yield 'registry (1.15)' => [RemovedPortsPass::REGISTRY, '1.15', WorkflowRuntimeInterface::class];
        yield 'UI access checker (1.14)' => [RemovedPortsPass::UI_ACCESS_CHECKER, '1.14', WorkflowUiAccessPolicyInterface::class];
        yield 'versioning (1.19, Q2)' => [RemovedPortsPass::VERSIONING, '1.19', WorkflowVersionManagerInterface::class];
    }

    #[Test]
    public function aCleanContainerPasses(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.unrelated', stdClass::class)->setDecoratedService('some.other.service');

        (new RemovedPortsPass())->process($container);

        $this->addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function anAliasOfARemovedPortFailsTheBuild(string $port, string $since, string $instead): void
    {
        $container = new ContainerBuilder();
        $container->register('app.legacy', stdClass::class);
        $container->setAlias($port, 'app.legacy');

        $this->assertFails($container, $port, $since, $instead, ['app.legacy']);
    }

    #[Test]
    #[DataProvider('removedPorts')]
    public function aServiceRegisteredUnderARemovedPortFailsTheBuild(string $port, string $since, string $instead): void
    {
        $container = new ContainerBuilder();
        $container->register($port, stdClass::class);

        $this->assertFails($container, $port, $since, $instead, [$port]);
    }

    /**
     * A decorator registered with on_invalid: ignore fails as well: Symfony would drop it silently (for the UI access
     * port, a dropped decorator could widen access).
     */
    #[Test]
    #[DataProvider('removedPorts')]
    public function aDecoratorOfARemovedPortFailsTheBuild(string $port, string $since, string $instead): void
    {
        $container = new ContainerBuilder();
        $container->register('app.audit', stdClass::class)->setDecoratedService($port);
        $container->register('app.optional', stdClass::class)->setDecoratedService($port, null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);

        $this->assertFails($container, $port, $since, $instead, ['app.audit', 'app.optional']);
    }

    /**
     * Audit XR-WF-M1: an application class that still implements a removed port cannot be loaded; resource discovery
     * keeps its definition with only a loading error (and Symfony would drop it as unused). The build fails.
     */
    #[Test]
    #[DataProvider('removedPorts')]
    public function aDefinitionThatCannotLoadBecauseOfARemovedPortFailsTheBuild(string $port, string $since, string $instead): void
    {
        $container = new ContainerBuilder();
        $container->register('App\\Workflow\\LegacyService')->addError(sprintf('Class "%s" not found while loading "App\\Workflow\\LegacyService".', $port));

        $this->assertFails($container, $port, $since, $instead, ['App\\Workflow\\LegacyService']);
    }

    #[Test]
    public function aLoadingErrorAboutAnotherClassIsLeftToSymfony(): void
    {
        $container = new ContainerBuilder();
        $container->register('App\\Broken')->addError('Class "App\\Missing" not found while loading "App\\Broken".');

        (new RemovedPortsPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<string> $services
     */
    private function assertFails(ContainerBuilder $container, string $port, string $since, string $instead, array $services): void
    {
        try {
            (new RemovedPortsPass())->process($container);
            self::fail('The removed port must fail the build.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"' . $port . '" was removed in 2.0 (deprecated since ' . $since . ')', $exception->getMessage());
            foreach ($services as $service) {
                self::assertStringContainsString('"' . $service . '"', $exception->getMessage());
            }
            self::assertStringContainsString($instead, $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        }
    }
}
