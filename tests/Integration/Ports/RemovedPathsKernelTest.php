<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Error;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\WorkflowBundle\Tests\Support\Removed\LegacyOnlyUiAccessChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function sys_get_temp_dir;

/**
 * 2.0: every host path that relied on something 1.x deprecated and 2.0 removed fails the container build of a real
 * kernel with a message that says what to do and points to UPGRADING.md "To 2.0.0" — never a silent fallback, a PHP
 * fatal error or a TypeError.
 */
final class RemovedPathsKernelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function removedPaths(): iterable
    {
        yield 'access checker on the removed port' => ['removed-checker', ['"app.workflow_checker"', LegacyOnlyUiAccessChecker::class, RemovedPortsPass::UI_ACCESS_CHECKER, WorkflowUiAccessPolicyInterface::class]];
        yield 'decorator of the removed UI access port' => ['removed-checker-decorator', ['"app.workflow_audit"', RemovedPortsPass::UI_ACCESS_CHECKER, WorkflowUiAccessPolicyInterface::class]];
        yield 'decorator of the removed registry port' => ['removed-registry-decorator', ['"app.workflow_registry_audit"', RemovedPortsPass::REGISTRY, WorkflowRuntimeInterface::class]];
        yield 'decorator of the removed versioning port (Q2)' => ['removed-versioning-decorator', ['"app.workflow_versioning_audit"', RemovedPortsPass::VERSIONING, WorkflowVersionManagerInterface::class]];
        yield 'private_entity_manager: false' => ['private-false', ['private_entity_manager: false was removed in 2.0', 'WorkflowDefinitionRepository::save()']];
    }

    /**
     * @param list<string> $expected fragments of the message
     */
    #[Test]
    #[DataProvider('removedPaths')]
    public function theBuildFailsWithAClearMessage(string $variant, array $expected): void
    {
        $kernel    = new PortsTestKernel($variant, false);
        $exception = null;

        try {
            $kernel->boot();
        } catch (Throwable $thrown) {
            $exception = $thrown;
        } finally {
            $kernel->shutdown();
        }

        self::assertNotNull($exception, 'The removed path must fail the build.');
        self::assertNotInstanceOf(Error::class, $exception, 'never a PHP fatal error or a TypeError: ' . $exception->getMessage());
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $exception->getMessage());
        }
        self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
    }
}
