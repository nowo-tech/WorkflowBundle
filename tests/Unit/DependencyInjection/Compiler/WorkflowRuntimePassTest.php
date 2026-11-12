<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowRuntimePass;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowRuntime;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\CountingRegistryDecorator;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\HostRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Runtime port wiring (1.15): the host's new port wins, a 1.14 registry (or decorator) keeps the deprecated SPI with a
 * deprecation, a runtime class under the old name moves to the new port, neither port fails the build, and tenancy
 * refuses a host registry.
 */
final class WorkflowRuntimePassTest extends TestCase
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
    public function builtInPortsWithoutDeprecation(): void
    {
        $container = $this->container();
        (new WorkflowRuntimePass())->process($container);

        self::assertSame('built-in', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER));
        self::assertSame('built-in', $container->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));
        self::assertSame([], $this->deprecations);

        $empty = new ContainerBuilder();
        (new WorkflowRuntimePass())->process($empty);
        self::assertFalse($empty->hasParameter(WorkflowRuntimePass::RUNTIME_PARAMETER), 'bundle not loaded');
    }

    #[Test]
    public function theHostsRuntimeWinsAndMustImplementThePort(): void
    {
        $container = $this->container();
        $container->register('app.runtime', HostRuntime::class);
        $container->setAlias(WorkflowRuntimeInterface::class, 'app.runtime');
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER));

        $container = $this->container();
        $container->register('app.decorator', HostRuntime::class)->setDecoratedService(WorkflowRuntimeInterface::class);
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER));

        $container = $this->container();
        $container->register('app.factory')->setFactory([stdClass::class, 'create']);
        $container->setAlias(WorkflowRuntimeInterface::class, 'app.factory');
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER), 'unknown class: trusted');

        $container = $this->container();
        $container->setAlias(WorkflowRuntimeInterface::class, 'app.missing');
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER), 'missing service: lint:container reports it');

        $container = $this->container();
        $container->register('app.wrong', stdClass::class);
        $container->setAlias(WorkflowRuntimeInterface::class, 'app.wrong');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement it');
        (new WorkflowRuntimePass())->process($container);
    }

    #[Test]
    public function a114RegistryKeepsTheSpiWithADeprecation(): void
    {
        $container = $this->container();
        $container->register('app.registry', CountingRegistryDecorator::class);
        $container->setAlias(WorkflowRegistryInterface::class, 'app.registry');
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));
        self::assertStringContainsString('"app.registry"', $this->deprecations[0] ?? '');

        $container = $this->container();
        $container->register('app.audit', CountingRegistryDecorator::class)->setDecoratedService(WorkflowRegistryInterface::class);
        $container->register('app.optional', CountingRegistryDecorator::class)->setDecoratedService(WorkflowRegistryInterface::class, null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        (new WorkflowRuntimePass())->process($container);
        self::assertStringContainsString('"app.audit"', $this->deprecations[1] ?? '');
        self::assertStringNotContainsString('app.optional', $this->deprecations[1] ?? '');
    }

    #[Test]
    public function aRuntimeClassUnderTheOldNameMovesToTheNewPort(): void
    {
        $container = $this->container();
        $container->register('app.runtime', HostRuntime::class);
        $container->setAlias(WorkflowRegistryInterface::class, 'app.runtime');
        (new WorkflowRuntimePass())->process($container);

        self::assertSame('app.runtime', (string) $container->getAlias(WorkflowRuntimeInterface::class));
        self::assertSame(DatabaseWorkflowRegistry::class, (string) $container->getAlias(WorkflowRegistryInterface::class));
        self::assertSame('built-in', $container->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));
    }

    #[Test]
    public function aClassOnNeitherPortFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.wrong', stdClass::class);
        $container->setAlias(WorkflowRegistryInterface::class, 'app.wrong');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('implements neither');
        (new WorkflowRuntimePass())->process($container);
    }

    #[Test]
    public function tenancyRefusesAHostRegistry(): void
    {
        $container = $this->container();
        $container->setParameter('nowo_workflow.tenancy.enabled', true);
        $container->register('app.audit', CountingRegistryDecorator::class)->setDecoratedService(WorkflowRegistryInterface::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tenancy');
        (new WorkflowRuntimePass())->process($container);
    }

    #[Test]
    public function aSyntheticOrMissingRegistryIsTrusted(): void
    {
        $container = $this->container();
        $container->register('app.synthetic')->setSynthetic(true);
        $container->setAlias(WorkflowRegistryInterface::class, 'app.synthetic');
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));

        $container = $this->container();
        $container->removeAlias(WorkflowRegistryInterface::class);
        (new WorkflowRuntimePass())->process($container);
        self::assertSame('built-in', $container->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowRuntime::class, WorkflowRuntime::class);
        $container->register(DatabaseWorkflowRegistry::class, DatabaseWorkflowRegistry::class);
        $container->setAlias(WorkflowRuntimeInterface::class, WorkflowRuntime::class);
        $container->setAlias(WorkflowRegistryInterface::class, DatabaseWorkflowRegistry::class);

        return $container;
    }
}
