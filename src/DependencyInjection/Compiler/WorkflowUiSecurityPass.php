<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Wires Workflow UI access checking after all extensions are merged.
 *
 * Cannot run in {@see NowoWorkflowExtension::load()} —
 * Symfony loads each extension against an isolated container where {@code has('security.authorization_checker')} is unreliable.
 */
final class WorkflowUiSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_workflow.enabled') || !$container->getParameter('nowo_workflow.enabled')) {
            return;
        }

        $allowUnauthenticated = (bool) $container->getParameter('nowo_workflow.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');
        $accessCheckerId      = $container->getParameter('nowo_workflow.security.access_checker');

        if (!$hasSecurity && !$allowUnauthenticated && (!is_string($accessCheckerId) || $accessCheckerId === '')) {
            throw new InvalidConfigurationException('nowo_workflow requires symfony/security-bundle (security.authorization_checker), or set nowo_workflow.security.allow_unauthenticated: true (dev/demo only — never in production), or set nowo_workflow.security.access_checker to a custom service id.');
        }

        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $container->setAlias(WorkflowUiAccessCheckerInterface::class, $accessCheckerId)
                ->setPublic(false);

            return;
        }

        if ($allowUnauthenticated || !$hasSecurity) {
            $container->setAlias(WorkflowUiAccessCheckerInterface::class, AllowAllWorkflowUiAccessChecker::class)
                ->setPublic(false);

            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $container->getParameter('nowo_workflow.security.access_roles');

        $container->register(RoleBasedWorkflowUiAccessChecker::class, RoleBasedWorkflowUiAccessChecker::class)
            ->setArgument('$requiredRoles', $accessRoles)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->setPublic(false);

        $container->setAlias(WorkflowUiAccessCheckerInterface::class, RoleBasedWorkflowUiAccessChecker::class)
            ->setPublic(false);
    }
}
