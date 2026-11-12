<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Tests\Support\Access\DualPredicate;
use Nowo\WorkflowBundle\Tests\Support\Access\LegacyPredicateChecker;
use Nowo\WorkflowBundle\Tests\Support\Access\NotAPort;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughLegacyDecorator;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughPolicyDecorator;
use Nowo\WorkflowBundle\Tests\Support\Access\PolicyPredicate;
use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiSecurityPassV1130;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.14 port wiring of {@see WorkflowUiSecurityPass} (REQ-ARCH-001 rule 3): which service sits behind each port and
 * which port(s) the subscriber asks, for every host shape; plus the 1.13.0 wiring equivalence for every host that
 * does not use the new port.
 */
final class WorkflowUiSecurityPassPortsTest extends TestCase
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
    public function builtInRoleBasedSitsBehindBothPortsAndOnlyTheNewPortIsAsked(): void
    {
        $container = $this->container(security: true);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertTrue($container->getAlias(WorkflowUiAccessCheckerInterface::class)->isDeprecated());
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        $arguments = $container->getDefinition(WorkflowUiAccessSubscriber::class)->getArguments();
        self::assertNull($arguments['$accessChecker']);
        self::assertEquals(new Reference(WorkflowUiAccessPolicyInterface::class), $arguments['$accessPolicy']);
        self::assertInstanceOf(Reference::class, $arguments['$tokenStorage']);
        self::assertSame('security.token_storage', (string) $arguments['$tokenStorage']);
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aNewPortServiceIsAskedAndGetsNoDeprecatedAlias(): void
    {
        $container = $this->container(accessChecker: 'app.policy');
        $container->register('app.policy', PolicyPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertFalse($container->hasAlias(WorkflowUiAccessCheckerInterface::class), 'no deprecated alias for a class that does not implement it');
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aDeprecatedPortServiceKeepsThe113WiringWithADeprecation(): void
    {
        $container = $this->container(accessChecker: 'app.checker');
        $container->register('app.checker', LegacyPredicateChecker::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.checker', (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertTrue($container->getAlias(WorkflowUiAccessCheckerInterface::class)->isDeprecated());
        self::assertSame(WorkflowUiSecurityPass::MODE_LEGACY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        $arguments = $container->getDefinition(WorkflowUiAccessSubscriber::class)->getArguments();
        self::assertEquals(new Reference(WorkflowUiAccessCheckerInterface::class), $arguments['$accessChecker']);
        self::assertNull($arguments['$accessPolicy']);
        self::assertNull($arguments['$tokenStorage']);
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('implements only', $this->deprecations[0]);
    }

    #[Test]
    public function aDualServiceIsAskedThroughTheNewPort(): void
    {
        $container = $this->container(accessChecker: 'app.dual');
        $container->register('app.dual', DualPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.dual', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame('app.dual', (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aDualServiceWithADeprecatedPortDecoratorAsksBothPorts(): void
    {
        $container = $this->container(accessChecker: 'app.dual');
        $container->register('app.dual', DualPredicate::class);
        $container->register('app.audit', PassThroughLegacyDecorator::class)->setDecoratedService(WorkflowUiAccessCheckerInterface::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aServiceOnNeitherPortFailsTheBuild(): void
    {
        $container = $this->container(accessChecker: 'app.wrong');
        $container->register('app.wrong', NotAPort::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');

        (new WorkflowUiSecurityPass())->process($container);
    }

    #[Test]
    public function aFactoryServiceKeepsThe113WiringWithoutDeprecation(): void
    {
        $container = $this->container(accessChecker: 'app.factory');
        $container->register('app.factory', LegacyPredicateChecker::class)->setFactory([stdClass::class, 'create']);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.factory', (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertFalse($container->getAlias(WorkflowUiAccessCheckerInterface::class)->isDeprecated());
        self::assertSame(WorkflowUiSecurityPass::MODE_LEGACY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aSyntheticOrMissingServiceKeepsThe113Wiring(): void
    {
        $container = $this->container(accessChecker: 'app.synthetic');
        $container->register('app.synthetic')->setSynthetic(true);

        (new WorkflowUiSecurityPass())->process($container);
        self::assertSame(WorkflowUiSecurityPass::MODE_LEGACY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));

        $container = $this->container(accessChecker: 'app.missing');
        (new WorkflowUiSecurityPass())->process($container);
        self::assertSame('app.missing', (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
    }

    #[Test]
    public function aConfiguredServiceIsFoundThroughAnAliasChain(): void
    {
        $container = $this->container(accessChecker: 'app.alias');
        $container->setAlias('app.alias', 'app.alias2');
        $container->setAlias('app.alias2', 'app.policy');
        $container->register('app.policy', PolicyPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.alias', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aDefinitionWithoutClassUsesItsIdAsClass(): void
    {
        $container = $this->container(accessChecker: PolicyPredicate::class);
        $container->setDefinition(PolicyPredicate::class, new Definition());

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(PolicyPredicate::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    #[Test]
    public function theHostsOwnNewPortAliasWinsOverTheBuiltIn(): void
    {
        $container = $this->container(security: true);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function theHostsOwnNewPortDefinitionWins(): void
    {
        $container = $this->container(allowUnauthenticated: true);
        $container->removeAlias(WorkflowUiAccessPolicyInterface::class);
        $container->register(WorkflowUiAccessPolicyInterface::class, PolicyPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertFalse($container->hasAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aDeprecatedPortDecoratorOverTheBuiltInKeepsThe113Path(): void
    {
        $container = $this->container(security: true);
        $container->register('app.audit', PassThroughLegacyDecorator::class)->setDecoratedService(WorkflowUiAccessCheckerInterface::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_LEGACY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
    }

    #[Test]
    public function aDeprecatedPortDecoratorWithTheHostsNewPortAsksBoth(): void
    {
        $container = $this->container(security: true);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');
        $container->register('app.audit', PassThroughLegacyDecorator::class)->setDecoratedService(WorkflowUiAccessCheckerInterface::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aNewPortDecoratorAndADeprecatedPortDecoratorAreBothAsked(): void
    {
        $container = $this->container(security: true);
        $container->register('app.audit', PassThroughLegacyDecorator::class)->setDecoratedService(WorkflowUiAccessCheckerInterface::class);
        $container->register('app.new_audit', PassThroughPolicyDecorator::class)->setDecoratedService(WorkflowUiAccessPolicyInterface::class);

        (new WorkflowUiSecurityPass())->process($container);

        // NF-1b: the deprecated-port decorator is never dropped (dropping it could widen access).
        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        // NF-1a: the 1.14 decorator wraps the built-in selected by the configuration, not the deny-all default.
        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('"app.audit"', $this->deprecations[0]);
    }

    #[Test]
    public function aNewPortDecoratorAloneWrapsTheConfiguredBuiltIn(): void
    {
        foreach ([[true, false, RoleBasedWorkflowUiAccessChecker::class], [false, true, AllowAllWorkflowUiAccessChecker::class]] as [$security, $allow, $builtIn]) {
            $container = $this->container(security: $security, allowUnauthenticated: $allow);
            $container->register('app.new_audit', PassThroughPolicyDecorator::class)->setDecoratedService(WorkflowUiAccessPolicyInterface::class);

            (new WorkflowUiSecurityPass())->process($container);

            self::assertSame($builtIn, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
            self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        }
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aNewPortDecoratorOverADeprecatedPortOnlyCheckerFailsTheBuild(): void
    {
        foreach (['app.checker', 'app.factory'] as $checker) {
            $container = $this->container(accessChecker: $checker);
            $container->register('app.new_audit', PassThroughPolicyDecorator::class)->setDecoratedService(WorkflowUiAccessPolicyInterface::class);

            try {
                (new WorkflowUiSecurityPass())->process($container);
                self::fail('A 1.14 decorator must not wrap the deny-all default.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('"app.new_audit"', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aDeprecatedPortDecoratorWithANewPortDecoratorOverADualServiceAsksBoth(): void
    {
        $container = $this->container(accessChecker: 'app.dual');
        $container->register('app.dual', DualPredicate::class);
        $container->register('app.audit', PassThroughLegacyDecorator::class)->setDecoratedService(WorkflowUiAccessCheckerInterface::class);
        $container->register('app.new_audit', PassThroughPolicyDecorator::class)->setDecoratedService(WorkflowUiAccessPolicyInterface::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame('app.dual', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    #[Test]
    public function aDeprecatedPortServiceWithTheHostsNewPortAsksBoth(): void
    {
        $container = $this->container(accessChecker: 'app.checker');
        $container->register('app.checker', LegacyPredicateChecker::class);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertStringContainsString('"app.checker"', $this->deprecations[1] ?? '', 'the both-ports deprecation names the service to migrate');
    }

    #[Test]
    public function aFactoryServiceWithTheHostsNewPortAsksBoth(): void
    {
        $container = $this->container(accessChecker: 'app.factory');
        $container->register('app.factory', LegacyPredicateChecker::class)->setFactory([stdClass::class, 'create']);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(WorkflowUiSecurityPass::MODE_BOTH, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function disabledWithoutSecurityKeepsTheHostsNewPort(): void
    {
        $container = $this->container();
        $container->setParameter('nowo_workflow.enabled', false);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(DenyAllWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class));
    }

    #[Test]
    public function withoutTheSubscriberOnlyTheAliasesAreSet(): void
    {
        $container = $this->container(allowUnauthenticated: true);
        $container->removeDefinition(WorkflowUiAccessSubscriber::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(AllowAllWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    /**
     * @return iterable<string, array{array{security?: bool, allowUnauthenticated?: bool, accessChecker?: string|null, enabled?: bool}}>
     */
    public static function hostsWithoutTheNewPort(): iterable
    {
        yield 'role based' => [['security' => true]];
        yield 'role based, disabled' => [['security' => true, 'enabled' => false]];
        yield 'allow_unauthenticated' => [['allowUnauthenticated' => true]];
        yield 'allow_unauthenticated with security' => [['allowUnauthenticated' => true, 'security' => true]];
        yield 'disabled without security' => [['enabled' => false]];
        yield 'deprecated-port checker' => [['accessChecker' => 'app.checker']];
        yield 'deprecated-port checker with security' => [['accessChecker' => 'app.checker', 'security' => true]];
        yield 'unknown checker class' => [['accessChecker' => 'app.factory']];
    }

    /**
     * The service the 1.14 subscriber asks is the service the 1.13.0 pass put behind the deprecated port.
     *
     * @param array{security?: bool, allowUnauthenticated?: bool, accessChecker?: string|null, enabled?: bool} $shape
     */
    #[Test]
    #[DataProvider('hostsWithoutTheNewPort')]
    public function theAskedServiceIsThe113One(array $shape): void
    {
        $legacy = $this->container(...$shape);
        (new WorkflowUiSecurityPassV1130())->process($legacy);

        $current = $this->container(...$shape);
        (new WorkflowUiSecurityPass())->process($current);

        $expected = (string) $legacy->getAlias(WorkflowUiAccessCheckerInterface::class);
        $mode     = $current->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER);
        $port     = $mode === WorkflowUiSecurityPass::MODE_POLICY ? WorkflowUiAccessPolicyInterface::class : WorkflowUiAccessCheckerInterface::class;
        self::assertNotSame(WorkflowUiSecurityPass::MODE_BOTH, $mode);
        self::assertSame($expected, (string) $current->getAlias($port));
        if ($legacy->hasDefinition(RoleBasedWorkflowUiAccessChecker::class)) {
            self::assertEquals($legacy->getDefinition(RoleBasedWorkflowUiAccessChecker::class)->getArguments(), $current->getDefinition(RoleBasedWorkflowUiAccessChecker::class)->getArguments());
        }
    }

    private function container(bool $security = false, bool $allowUnauthenticated = false, ?string $accessChecker = null, bool $enabled = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_workflow.enabled', $enabled);
        $container->setParameter('nowo_workflow.security.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_workflow.security.access_checker', $accessChecker);
        $container->setParameter('nowo_workflow.security.access_roles', ['ROLE_ADMIN', 'ROLE_EDITOR']);
        $container->register(AllowAllWorkflowUiAccessChecker::class, AllowAllWorkflowUiAccessChecker::class);
        $container->register(DenyAllWorkflowUiAccessChecker::class, DenyAllWorkflowUiAccessChecker::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, DenyAllWorkflowUiAccessChecker::class);
        $container->setAlias(WorkflowUiAccessCheckerInterface::class, DenyAllWorkflowUiAccessChecker::class);
        $container->register(WorkflowUiAccessSubscriber::class, WorkflowUiAccessSubscriber::class);
        $container->register('app.checker', LegacyPredicateChecker::class);
        $container->register('app.factory', LegacyPredicateChecker::class)->setFactory([stdClass::class, 'create']);
        if ($security) {
            $container->setDefinition('security.authorization_checker', new Definition());
        }

        return $container;
    }
}
