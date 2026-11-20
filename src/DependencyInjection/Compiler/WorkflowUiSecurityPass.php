<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use ReflectionClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Wires Workflow UI access checking after all extensions are merged.
 *
 * Cannot run in {@see NowoWorkflowExtension::load()} —
 * Symfony loads each extension against an isolated container where {@code has('security.authorization_checker')} is unreliable.
 *
 * The access port is the framework-free {@see WorkflowUiAccessPolicyInterface} (since 1.14; the request-based 1.x port
 * was removed in 2.0, see {@see RemovedPortsPass}). The pass picks the service behind it and wires
 * {@see WorkflowUiAccessSubscriber}:
 *
 * - `security.access_checker` names a service whose class implements the port: the port is aliased to it; a class that
 *   does not implement it, or cannot be loaded (e.g. it still implements the removed interface), fails the build; a
 *   service whose class cannot be known before compilation (factory, synthetic) is aliased as it is;
 * - no `access_checker`: the built-in policy (role based, allow-all for allow_unauthenticated, deny-all) sits behind
 *   the port; a host decorator of the port wraps that built-in (audit NF-1a);
 * - the host aliased (or defined) the port itself: that service wins over the built-in.
 *
 * Runs before optimization: host definitions are loaded, decorators are not applied yet (they follow the alias).
 */
final class WorkflowUiSecurityPass implements CompilerPassInterface
{
    /** The subscriber asks the access policy (the only mode since 2.0). */
    public const MODE_POLICY = 'policy';

    /** Container parameter holding the mode chosen (debugging and tests): always {@see self::MODE_POLICY} since 2.0. */
    public const MODE_PARAMETER = 'nowo_workflow.security.access_mode';

    /** Built-in services that may sit behind the port (anything else behind its alias is a host service). */
    private const BUILT_INS = [
        AllowAllWorkflowUiAccessChecker::class,
        DenyAllWorkflowUiAccessChecker::class,
        RoleBasedWorkflowUiAccessChecker::class,
    ];

    /**
     * Aliases the Workflow UI access port (role based, custom, allow-all or deny-all), wires the subscriber and fails
     * when no safe option is configured.
     *
     * @throws InvalidConfigurationException when the UI is enabled without security, allow_unauthenticated or a checker
     * @throws LogicException when security.access_checker does not implement the port, cannot be loaded, or competes
     *                        with the application's own alias of the port
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_workflow.security.allow_unauthenticated')) {
            // Extension not loaded: keep the fail-closed default alias from services.yaml.
            return;
        }

        // Security wiring is never skipped: with enabled: false the UI routes answer 404
        // (WorkflowUiAccessSubscriber) and the checker still fails closed.
        $enabled              = !$container->hasParameter('nowo_workflow.enabled') || (bool) $container->getParameter('nowo_workflow.enabled');
        $allowUnauthenticated = (bool) $container->getParameter('nowo_workflow.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');
        $accessCheckerId      = $container->getParameter('nowo_workflow.security.access_checker');
        $hostPolicyAlias      = $this->isHostPolicyAlias($container);

        if (!$hasSecurity && !$allowUnauthenticated && (!is_string($accessCheckerId) || $accessCheckerId === '')) {
            if (!$enabled) {
                $this->builtIn($container, DenyAllWorkflowUiAccessChecker::class, $hostPolicyAlias);

                return;
            }

            throw new InvalidConfigurationException('nowo_workflow requires symfony/security-bundle (security.authorization_checker), or set nowo_workflow.security.allow_unauthenticated: true (dev/demo only — never in production), or set nowo_workflow.security.access_checker to a custom service id.');
        }

        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $this->configured($container, $accessCheckerId, $hostPolicyAlias);

            return;
        }

        if ($allowUnauthenticated || !$hasSecurity) {
            $this->builtIn($container, AllowAllWorkflowUiAccessChecker::class, $hostPolicyAlias);

            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $container->getParameter('nowo_workflow.security.access_roles');

        $container->register(RoleBasedWorkflowUiAccessChecker::class, RoleBasedWorkflowUiAccessChecker::class)
            ->setArgument('$requiredRoles', $accessRoles)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->setPublic(false);

        $this->builtIn($container, RoleBasedWorkflowUiAccessChecker::class, $hostPolicyAlias);
    }

    /**
     * A built-in service behind the port (the host's own alias, when there is one, keeps the port; a host decorator of
     * the port wraps the built-in).
     *
     * @param class-string $builtIn
     */
    private function builtIn(ContainerBuilder $container, string $builtIn, bool $hostPolicyAlias): void
    {
        if (!$hostPolicyAlias) {
            $container->setAlias(WorkflowUiAccessPolicyInterface::class, $builtIn)->setPublic(false);
        }

        $this->wire($container);
    }

    /**
     * The service named by `security.access_checker`.
     */
    private function configured(ContainerBuilder $container, string $id, bool $hostPolicyAlias): void
    {
        $class = $this->classOf($container, $id);

        if ($class !== null) {
            // Never load the class directly: one that still implements the removed interface cannot load.
            $reflection = $container->getReflectionClass($class, false);
            if (!$reflection instanceof ReflectionClass) {
                throw new LogicException(sprintf('nowo_workflow.security.access_checker "%s": the class "%s" cannot be loaded (it does not exist, or it still implements "%s", removed in 2.0: implement "%s" instead). See UPGRADING.md "To 2.0.0".', $id, $class, RemovedPortsPass::UI_ACCESS_CHECKER, WorkflowUiAccessPolicyInterface::class));
            }
            if (!$reflection->implementsInterface(WorkflowUiAccessPolicyInterface::class)) {
                throw new LogicException(sprintf('nowo_workflow.security.access_checker "%s" (%s) must implement "%s" (the request-based "%s" was removed in 2.0). See UPGRADING.md "To 2.0.0".', $id, $class, WorkflowUiAccessPolicyInterface::class, RemovedPortsPass::UI_ACCESS_CHECKER));
            }
        }

        if ($hostPolicyAlias && $this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class) !== $this->resolveAlias($container, $id)) {
            // Audit NF2-4a: never replace the host's own alias of the port silently.
            throw new LogicException(sprintf('"%s" is aliased to "%s" by the application and nowo_workflow.security.access_checker names "%s": keep only one of them.', WorkflowUiAccessPolicyInterface::class, $this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class), $id));
        }

        // A class that cannot be known before compilation (factory, synthetic, missing service) is aliased as it is:
        // the subscriber's typed argument refuses a service of the wrong type.
        $container->setAlias(WorkflowUiAccessPolicyInterface::class, $id)->setPublic(false);
        $this->wire($container);
    }

    /**
     * Gives the subscriber the access policy.
     */
    private function wire(ContainerBuilder $container): void
    {
        $container->setParameter(self::MODE_PARAMETER, self::MODE_POLICY);

        if (!$container->hasDefinition(WorkflowUiAccessSubscriber::class)) {
            return;
        }

        $container->getDefinition(WorkflowUiAccessSubscriber::class)
            ->setArgument('$accessPolicy', new Reference(WorkflowUiAccessPolicyInterface::class))
            ->setArgument('$tokenStorage', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE));
    }

    /**
     * True when the host aliased (or defined) the port itself, i.e. it no longer resolves to a built-in.
     */
    private function isHostPolicyAlias(ContainerBuilder $container): bool
    {
        if (!$container->hasAlias(WorkflowUiAccessPolicyInterface::class)) {
            return $container->hasDefinition(WorkflowUiAccessPolicyInterface::class);
        }

        return !in_array($this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class), self::BUILT_INS, true);
    }

    private function resolveAlias(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * The class of a service definition, null when it cannot be known before the container is compiled (factory,
     * synthetic, no definition). The class is not loaded here.
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        $id = $this->resolveAlias($container, $service);

        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) ? $class : null;
    }
}
