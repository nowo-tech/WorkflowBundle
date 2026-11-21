<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowRuntimePass;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowRuntime;
use Nowo\WorkflowBundle\Tests\Support\Removed\LegacyRegistryRuntime;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\HostRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Runtime port wiring (1.15): the host's port wins and must implement it. The 1.x registry port was removed in 2.0
 * (see RemovedPortsPassTest).
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

    /**
     * 2.0: a host runtime class that still implements the removed registry port cannot be loaded: the build fails with
     * a message, never with a PHP fatal error. A class that does not exist fails the same way.
     */
    #[Test]
    public function aRuntimeClassThatCannotBeLoadedFailsTheBuild(): void
    {
        foreach ([LegacyRegistryRuntime::class, 'App\\Workflow\\NoSuchRuntime'] as $class) {
            $container = $this->container();
            $container->register('app.runtime', $class);
            $container->setAlias(WorkflowRuntimeInterface::class, 'app.runtime');

            try {
                (new WorkflowRuntimePass())->process($container);
                self::fail('A runtime class that cannot be loaded must fail the build.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('"' . $class . '" cannot be loaded', $exception->getMessage());
                self::assertStringContainsString(RemovedPortsPass::REGISTRY, $exception->getMessage());
                self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aRuntimeClassThatIsNotAStringIsTrusted(): void
    {
        $container = $this->container();
        $container->setParameter('app.runtime_class', ['not', 'a', 'class']);
        $container->register('app.runtime', '%app.runtime_class%');
        $container->setAlias(WorkflowRuntimeInterface::class, 'app.runtime');

        (new WorkflowRuntimePass())->process($container);

        self::assertSame('host', $container->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowRuntime::class, WorkflowRuntime::class);
        $container->register(DatabaseWorkflowRegistry::class, DatabaseWorkflowRegistry::class);
        $container->setAlias(WorkflowRuntimeInterface::class, WorkflowRuntime::class);

        return $container;
    }
}
