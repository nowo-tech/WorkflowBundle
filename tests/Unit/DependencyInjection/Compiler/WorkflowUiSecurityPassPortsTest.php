<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Tests\Support\Access\NotAPort;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughPolicyDecorator;
use Nowo\WorkflowBundle\Tests\Support\Access\PolicyPredicate;
use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiAccessCheckerInterfaceV1130;
use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiSecurityPassV1130;
use Nowo\WorkflowBundle\Tests\Support\Removed\LegacyOnlyUiAccessChecker;
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
 * Port wiring of {@see WorkflowUiSecurityPass} (REQ-ARCH-001 rule 3): which service sits behind the access policy
 * port for every host shape, the build errors of 2.0 for a checker on the removed request-based port, plus the 1.13.0
 * wiring equivalence for the built-in policies.
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
    public function builtInRoleBasedSitsBehindThePort(): void
    {
        $container = $this->container(security: true);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(RoleBasedWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertFalse($container->hasAlias(RemovedPortsPass::UI_ACCESS_CHECKER), 'the removed port is never aliased');
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        $arguments = $container->getDefinition(WorkflowUiAccessSubscriber::class)->getArguments();
        self::assertArrayNotHasKey('$accessChecker', $arguments);
        self::assertEquals(new Reference(WorkflowUiAccessPolicyInterface::class), $arguments['$accessPolicy']);
        self::assertInstanceOf(Reference::class, $arguments['$tokenStorage']);
        self::assertSame('security.token_storage', (string) $arguments['$tokenStorage']);
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aPolicyServiceIsAsked(): void
    {
        $container = $this->container(accessChecker: 'app.policy');
        $container->register('app.policy', PolicyPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        self::assertSame([], $this->deprecations);
    }

    /**
     * 2.0: a configured checker that still implements only the removed request-based port cannot even be loaded; the
     * build fails with a message naming the class and the port to implement, never with a PHP fatal error.
     */
    #[Test]
    public function aCheckerOnTheRemovedPortFailsTheBuild(): void
    {
        $container = $this->container(accessChecker: 'app.legacy');
        $container->register('app.legacy', LegacyOnlyUiAccessChecker::class);

        try {
            (new WorkflowUiSecurityPass())->process($container);
            self::fail('A checker on the removed port must fail the build.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"app.legacy"', $exception->getMessage());
            self::assertStringContainsString(LegacyOnlyUiAccessChecker::class, $exception->getMessage());
            self::assertStringContainsString(RemovedPortsPass::UI_ACCESS_CHECKER, $exception->getMessage());
            self::assertStringContainsString(WorkflowUiAccessPolicyInterface::class, $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        }
    }

    #[Test]
    public function aCheckerOfAMissingClassFailsTheBuild(): void
    {
        $container = $this->container(accessChecker: 'app.typo');
        $container->register('app.typo', 'App\\Security\\NoSuchChecker');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be loaded');

        (new WorkflowUiSecurityPass())->process($container);
    }

    #[Test]
    public function aConfiguredPolicyServiceNeverReplacesTheHostsOwnAlias(): void
    {
        $container = $this->container(accessChecker: 'app.other');
        $container->register('app.other', PolicyPredicate::class);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        try {
            (new WorkflowUiSecurityPass())->process($container);
            self::fail('NF2-4a: two different policy services.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"app.policy"', $exception->getMessage());
        }

        $container = $this->container(accessChecker: 'app.policy');
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');
        (new WorkflowUiSecurityPass())->process($container);
        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class), 'the same service twice is fine');
    }

    #[Test]
    public function aServiceThatDoesNotImplementThePortFailsTheBuild(): void
    {
        $container = $this->container(accessChecker: 'app.wrong');
        $container->register('app.wrong', NotAPort::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');

        (new WorkflowUiSecurityPass())->process($container);
    }

    #[Test]
    public function aFactorySyntheticOrMissingServiceIsAliasedAsItIs(): void
    {
        $shapes = [
            'app.factory'   => static fn (ContainerBuilder $c): Definition => $c->register('app.factory', PolicyPredicate::class)->setFactory([stdClass::class, 'create']),
            'app.synthetic' => static fn (ContainerBuilder $c): Definition => $c->register('app.synthetic')->setSynthetic(true),
            'app.missing'   => null,
        ];
        foreach ($shapes as $id => $register) {
            $container = $this->container(accessChecker: $id);
            if ($register !== null) {
                $register($container);
            }

            (new WorkflowUiSecurityPass())->process($container);

            self::assertSame($id, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
            self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        }
        self::assertSame([], $this->deprecations);
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
    public function aDefinitionWhoseClassIsNotAStringIsAliasedAsItIs(): void
    {
        $container = $this->container(accessChecker: 'app.dynamic');
        $container->setParameter('app.checker_class', ['not', 'a', 'class']);
        $container->register('app.dynamic', '%app.checker_class%');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.dynamic', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    #[Test]
    public function theHostsOwnPortAliasWinsOverTheBuiltIn(): void
    {
        $container = $this->container(security: true);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function theHostsOwnPortDefinitionWins(): void
    {
        $container = $this->container(allowUnauthenticated: true);
        $container->removeAlias(WorkflowUiAccessPolicyInterface::class);
        $container->register(WorkflowUiAccessPolicyInterface::class, PolicyPredicate::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertFalse($container->hasAlias(WorkflowUiAccessPolicyInterface::class));
        self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
    }

    #[Test]
    public function aPortDecoratorAloneWrapsTheConfiguredBuiltIn(): void
    {
        foreach ([[true, false, RoleBasedWorkflowUiAccessChecker::class], [false, true, AllowAllWorkflowUiAccessChecker::class]] as [$security, $allow, $builtIn]) {
            $container = $this->container(security: $security, allowUnauthenticated: $allow);
            $container->register('app.new_audit', PassThroughPolicyDecorator::class)->setDecoratedService(WorkflowUiAccessPolicyInterface::class);

            (new WorkflowUiSecurityPass())->process($container);

            // NF-1a: the decorator wraps the built-in selected by the configuration, not the deny-all default.
            self::assertSame($builtIn, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
            self::assertSame(WorkflowUiSecurityPass::MODE_POLICY, $container->getParameter(WorkflowUiSecurityPass::MODE_PARAMETER));
        }
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function disabledWithoutSecurityKeepsTheHostsPort(): void
    {
        $container = $this->container();
        $container->setParameter('nowo_workflow.enabled', false);
        $container->register('app.policy', PolicyPredicate::class);
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, 'app.policy');

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame('app.policy', (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    #[Test]
    public function withoutTheSubscriberOnlyTheAliasIsSet(): void
    {
        $container = $this->container(allowUnauthenticated: true);
        $container->removeDefinition(WorkflowUiAccessSubscriber::class);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(AllowAllWorkflowUiAccessChecker::class, (string) $container->getAlias(WorkflowUiAccessPolicyInterface::class));
    }

    /**
     * @return iterable<string, array{array{security?: bool, allowUnauthenticated?: bool, enabled?: bool}}>
     */
    public static function hostsOnTheBuiltIns(): iterable
    {
        yield 'role based' => [['security' => true]];
        yield 'role based, disabled' => [['security' => true, 'enabled' => false]];
        yield 'allow_unauthenticated' => [['allowUnauthenticated' => true]];
        yield 'allow_unauthenticated with security' => [['allowUnauthenticated' => true, 'security' => true]];
        yield 'disabled without security' => [['enabled' => false]];
    }

    /**
     * The service the subscriber asks is the built-in the 1.13.0 pass put behind its (request-based) port.
     *
     * @param array{security?: bool, allowUnauthenticated?: bool, enabled?: bool} $shape
     */
    #[Test]
    #[DataProvider('hostsOnTheBuiltIns')]
    public function theAskedServiceIsThe113One(array $shape): void
    {
        $legacy = $this->container(...$shape);
        $legacy->setAlias(WorkflowUiAccessCheckerInterfaceV1130::class, DenyAllWorkflowUiAccessChecker::class);
        (new WorkflowUiSecurityPassV1130())->process($legacy);

        $current = $this->container(...$shape);
        (new WorkflowUiSecurityPass())->process($current);

        self::assertSame((string) $legacy->getAlias(WorkflowUiAccessCheckerInterfaceV1130::class), (string) $current->getAlias(WorkflowUiAccessPolicyInterface::class));
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
        $container->register(WorkflowUiAccessSubscriber::class, WorkflowUiAccessSubscriber::class);
        if ($security) {
            $container->setDefinition('security.authorization_checker', new Definition());
        }

        return $container;
    }
}
