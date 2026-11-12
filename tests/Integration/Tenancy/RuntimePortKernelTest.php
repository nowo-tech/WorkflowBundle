<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowRuntimePass;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\CountingRegistryDecorator;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\HostRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * The framework-free runtime port (1.15, REQ-ARCH-001 rule 3): container shapes (lint:container), equivalence with
 * WorkflowApplicator, the deprecated registry SPI kept for 1.14 decorators, and a DDD host using only the port.
 */
final class RuntimePortKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private ?TenancyTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function variants(): iterable
    {
        yield 'standard host, tenancy' => ['tenancy', 'built-in', 'built-in'];
        yield 'DDD host, tenancy' => ['tenancy-ddd', 'built-in', 'built-in'];
        yield 'no tenancy (1.14)' => ['plain', 'built-in', 'built-in'];
        yield '1.14 registry decorator' => ['legacy-registry', 'built-in', 'host'];
        yield 'host runtime port' => ['host-runtime', 'host', 'built-in'];
    }

    #[Test]
    #[DataProvider('variants')]
    public function lintContainerPassesForEveryShape(string $variant, string $runtime, string $registry): void
    {
        $this->kernel = new TenancyTestKernel($variant, true);
        $this->kernel->boot();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame($runtime, $this->kernel->getContainer()->getParameter(WorkflowRuntimePass::RUNTIME_PARAMETER));
        self::assertSame($registry, $this->kernel->getContainer()->getParameter(WorkflowRuntimePass::REGISTRY_PARAMETER));
    }

    #[Test]
    public function theRuntimePortGivesTheApplicatorsMarkingsAndResults(): void
    {
        $this->kernel = $kernel = self::boot('plain');
        self::seedLeaveTemplate($kernel);
        $runtime = self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class);

        foreach ([1, 3] as $days) {
            $viaApplicator = self::persistLeave($kernel, new LeaveRequest('leave-a' . $days, '', $days));
            $viaRuntime    = self::persistLeave($kernel, new LeaveRequest('leave-r' . $days, '', $days));

            self::assertSame(self::applicator($kernel)->getEnabledTransitions($viaApplicator, 'leave'), $runtime->enabledTransitions($viaRuntime, 'leave'));
            self::assertSame(self::applicator($kernel)->getMarking($viaApplicator, 'leave'), $runtime->marking($viaRuntime, 'leave')->place());

            self::applicator($kernel)->apply($viaApplicator, 'leave', 'submit');
            $result = $runtime->apply($viaRuntime, 'leave', 'submit');
            self::assertTrue($result->applied);
            self::assertSame($viaApplicator->getStatus(), $viaRuntime->getStatus(), 'same marking, days ' . $days);
            self::assertSame($viaApplicator->getStatus(), $result->marking->place());
            // No variables provider in this kernel: the condition of auto_approve fails closed.
            self::assertSame([], $result->automaticTransitions);
            self::assertSame('submitted', $viaRuntime->getStatus());
            self::assertSame(1, $result->marking->definitionVersion);
            self::assertNull($result->marking->definitionTenant);

            foreach (['approve', 'reject'] as $transition) {
                self::assertSame(self::applicator($kernel)->can($viaApplicator, 'leave', $transition), $runtime->can($viaRuntime, 'leave', $transition));
            }
            if ($days > 2) {
                try {
                    self::applicator($kernel)->apply($viaApplicator, 'leave', 'approve');
                    self::fail('No permission checker: the permission guard blocks.');
                } catch (WorkflowTransitionBlockedException $exception) {
                    $blocked = $runtime->apply($viaRuntime, 'leave', 'approve');
                    self::assertFalse($blocked->applied);
                    self::assertSame($exception->getBlockerCodes(), $blocked->blockerCodes);
                    self::assertSame('submitted', $blocked->marking->place());
                    self::assertTrue($blocked->marking->has('submitted'));
                }
            }
        }
    }

    #[Test]
    public function a114RegistryDecoratorKeepsRunningBehindTheRuntimePort(): void
    {
        $this->kernel = $kernel = self::boot('legacy-registry');
        self::seedLeaveTemplate($kernel);
        $decorator = self::service($kernel, CountingRegistryDecorator::class, 'app.workflow_registry_audit');
        $leave     = self::persistLeave($kernel, new LeaveRequest('leave-1', '', 5));

        self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class)->apply($leave, 'leave', 'submit');

        self::assertGreaterThan(0, $decorator->calls);
        self::assertSame('submitted', $leave->getStatus());
    }

    #[Test]
    public function theHostsRuntimePortWins(): void
    {
        $this->kernel = $kernel = self::boot('host-runtime');
        self::assertInstanceOf(HostRuntime::class, self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class));
    }

    /**
     * DDD host: plain-PHP subject (XML mapping, default connection), the bundle on its own `workflow` manager and
     * connection, and the host only talks to the framework-free ports.
     */
    #[Test]
    public function aDddHostRunsTenantWorkflowsThroughTheRuntimePortOnly(): void
    {
        $this->kernel = $kernel = self::boot('tenancy-ddd');
        self::seedLeaveTemplate($kernel, 'days <= 0');
        self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'), true);
        $runtime = self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class);
        $leave   = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));

        $result = $runtime->apply($leave, 'leave', 'submit');

        self::assertTrue($result->applied);
        self::assertSame('org-a', $result->marking->definitionTenant);
        self::assertSame(['submitted'], $result->marking->places);
        self::assertSame([], $runtime->enabledTransitions($leave, 'leave'), 'no actor: the permission guards block approve and reject');
        self::assertFalse($runtime->can($leave, 'leave', 'approve'));

        $doctrine = self::service($kernel, ManagerRegistry::class, 'doctrine');
        self::assertCount(1, $doctrine->getManager('workflow')->getRepository(WorkflowSubjectPin::class)->findAll(), 'pinned on the workflow connection');
        self::assertCount(1, self::audit($kernel)->events, 'the host flush on the default connection committed it');
        self::assertSame(0, self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class)->countPending());
    }
}
