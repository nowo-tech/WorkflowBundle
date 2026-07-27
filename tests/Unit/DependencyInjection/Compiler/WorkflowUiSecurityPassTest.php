<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class WorkflowUiSecurityPassTest extends TestCase
{
    #[Test]
    public function testSkipsWhenBundleDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_workflow.enabled', false);

        (new WorkflowUiSecurityPass())->process($container);

        self::assertFalse($container->hasAlias(WorkflowUiAccessCheckerInterface::class));
    }

    #[Test]
    public function testAllowsWhenUnauthenticatedFlagSet(): void
    {
        $container = $this->containerWithParams(allowUnauthenticated: true);
        (new WorkflowUiSecurityPass())->process($container);

        self::assertTrue($container->hasAlias(WorkflowUiAccessCheckerInterface::class));
        self::assertSame(
            AllowAllWorkflowUiAccessChecker::class,
            (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class),
        );
    }

    #[Test]
    public function testFailsWithoutSecurityAndWithoutAllowFlag(): void
    {
        $container = $this->containerWithParams(allowUnauthenticated: false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        (new WorkflowUiSecurityPass())->process($container);
    }

    #[Test]
    public function testWiresRoleBasedWhenSecurityPresent(): void
    {
        $container = $this->containerWithParams(allowUnauthenticated: false);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new WorkflowUiSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(RoleBasedWorkflowUiAccessChecker::class));
        self::assertSame(
            RoleBasedWorkflowUiAccessChecker::class,
            (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class),
        );
    }

    #[Test]
    public function testCustomAccessCheckerWins(): void
    {
        $container = $this->containerWithParams(
            allowUnauthenticated: false,
            accessChecker: 'app.workflow_access',
        );
        $container->setDefinition('app.workflow_access', new Definition());

        (new WorkflowUiSecurityPass())->process($container);

        self::assertSame(
            'app.workflow_access',
            (string) $container->getAlias(WorkflowUiAccessCheckerInterface::class),
        );
    }

    /**
     * @param list<string> $accessRoles
     */
    private function containerWithParams(
        bool $allowUnauthenticated,
        ?string $accessChecker = null,
        array $accessRoles = ['ROLE_ADMIN'],
    ): ContainerBuilder {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_workflow.enabled', true);
        $container->setParameter('nowo_workflow.security.allow_unauthenticated', $allowUnauthenticated);
        $container->setParameter('nowo_workflow.security.access_checker', $accessChecker);
        $container->setParameter('nowo_workflow.security.access_roles', $accessRoles);
        $container->register(AllowAllWorkflowUiAccessChecker::class, AllowAllWorkflowUiAccessChecker::class);

        return $container;
    }
}
