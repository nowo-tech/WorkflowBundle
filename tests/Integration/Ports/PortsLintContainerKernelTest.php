<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughPolicyDecorator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * `lint:container` passes for every host shape of the access port (a host policy, a decorator of the port, the
 * built-in policy, a DDD host), and every shape decides the same way for the same users (admin granted; user, editor
 * and anonymous denied). The shapes on the ports removed in 2.0 fail the build (RemovedPathsKernelTest).
 */
final class PortsLintContainerKernelTest extends TestCase
{
    use PortsKernelSupport;

    private ?PortsTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variants(): iterable
    {
        yield 'built-in policy' => ['builtin', WorkflowUiSecurityPass::MODE_POLICY];
        yield 'host policy' => ['new', WorkflowUiSecurityPass::MODE_POLICY];
        yield 'policy decorator' => ['policy-decorator', WorkflowUiSecurityPass::MODE_POLICY];
        yield 'DDD host' => ['ddd', WorkflowUiSecurityPass::MODE_POLICY];
    }

    #[Test]
    #[DataProvider('variants')]
    public function lintContainerPassesAndEveryShapeDecidesTheSame(string $variant, string $mode): void
    {
        $this->kernel = new PortsTestKernel($variant);
        $this->kernel->boot();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());

        self::assertSame($mode, $this->kernel->getContainer()->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));

        self::createSchemas($this->kernel);
        foreach (['admin' => 200, 'alice' => 403, 'bob' => 403, '' => 403] as $user => $status) {
            $response = self::send($this->kernel, '/workflow/definitions', $user === '' ? null : $user);
            self::assertSame($status, $response->getStatusCode(), $variant . ' / ' . ($user === '' ? 'anonymous' : $user));
        }

        if ($variant === 'policy-decorator') {
            $decorator = self::testContainer($this->kernel)->get('app.workflow_policy_audit');
            self::assertInstanceOf(PassThroughPolicyDecorator::class, $decorator);
            self::assertSame(4, $decorator->calls, 'the host decorator of the port wraps the role-based policy');
        }
    }
}
