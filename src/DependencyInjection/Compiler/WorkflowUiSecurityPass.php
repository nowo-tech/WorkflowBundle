<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function implode;
use function in_array;
use function is_a;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * Wires Workflow UI access checking after all extensions are merged.
 *
 * Cannot run in {@see NowoWorkflowExtension::load()} —
 * Symfony loads each extension against an isolated container where {@code has('security.authorization_checker')} is unreliable.
 *
 * Since 1.14 (REQ-ARCH-001 rule 3) there are two ports: the framework-free {@see WorkflowUiAccessPolicyInterface} and
 * the deprecated {@see WorkflowUiAccessCheckerInterface}. The pass picks the service behind each port and tells
 * {@see WorkflowUiAccessSubscriber} which one(s) to ask (when two are asked, both must grant):
 *
 * - `security.access_checker` names a service whose class implements the 1.14 port (alone or with the deprecated one):
 *   the 1.14 port is aliased to it and asked; the deprecated alias points at it only when the class also implements
 *   the deprecated port (otherwise there is no deprecated alias at all);
 * - its class implements only the deprecated port: the deprecated alias points at it and it is asked with the Symfony
 *   request as in 1.13 (a deprecation is triggered at build time); a class that implements neither port fails the
 *   build; a service whose class cannot be known before compilation (factory, synthetic) keeps the 1.13 wiring;
 * - no `access_checker`: the built-in policy (role based, allow-all for allow_unauthenticated, deny-all) sits behind
 *   both ports; a host decorator of the 1.14 port wraps that built-in (audit NF-1a);
 * - the host aliased (or decorated) the 1.14 port to its own service: that service wins over the built-in and is
 *   asked; a deprecated-port service the host configured too is asked as well;
 * - a host decorator of the deprecated port keeps that port asked (the decorator always runs): alone over the built-in
 *   (exactly 1.13), together with the host's 1.14 service or 1.14 decorator otherwise (audit NF-1b: a decorator of the
 *   deprecated port is never skipped, so it can never widen access by being dropped).
 *
 * Whenever both ports are asked (both must grant), a deprecation names the deprecated-port service(s) to migrate.
 *
 * Runs before optimization: host definitions are loaded, decorators are not applied yet (they follow the aliases).
 */
final class WorkflowUiSecurityPass implements CompilerPassInterface
{
    /** The subscriber asks only the 1.14 port. */
    public const MODE_POLICY = 'policy';

    /** The subscriber asks only the deprecated port (with the Symfony request, as in 1.13). */
    public const MODE_LEGACY = 'legacy';

    /** The subscriber asks both ports; both must grant. */
    public const MODE_BOTH = 'both';

    /** Container parameter holding the mode chosen (debugging and tests). */
    public const MODE_PARAMETER = 'nowo_workflow.security.access_mode';

    /** Built-in services that may sit behind the ports (anything else behind the 1.14 alias is a host service). */
    private const BUILT_INS = [
        AllowAllWorkflowUiAccessChecker::class,
        DenyAllWorkflowUiAccessChecker::class,
        RoleBasedWorkflowUiAccessChecker::class,
    ];

    /**
     * Aliases the Workflow UI access ports (role based, custom, allow-all or deny-all), wires the subscriber and fails
     * when no safe option is configured.
     *
     * @throws InvalidConfigurationException when the UI is enabled without security, allow_unauthenticated or a checker
     * @throws LogicException when security.access_checker implements neither port, or the 1.14 port is decorated over a
     *                        deprecated-port-only access_checker
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_workflow.security.allow_unauthenticated')) {
            // Extension not loaded: keep the fail-closed default aliases from services.yaml.
            return;
        }

        // Security wiring is never skipped: with enabled: false the UI routes answer 404
        // (WorkflowUiAccessSubscriber) and the checker still fails closed.
        $enabled              = !$container->hasParameter('nowo_workflow.enabled') || (bool) $container->getParameter('nowo_workflow.enabled');
        $allowUnauthenticated = (bool) $container->getParameter('nowo_workflow.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');
        $accessCheckerId      = $container->getParameter('nowo_workflow.security.access_checker');
        $hostPolicyAlias      = $this->isHostPolicyAlias($container);
        $hostPolicy           = $hostPolicyAlias || $this->isDecorated($container, WorkflowUiAccessPolicyInterface::class);

        if (!$hasSecurity && !$allowUnauthenticated && (!is_string($accessCheckerId) || $accessCheckerId === '')) {
            if (!$enabled) {
                $this->wire($container, $this->builtIn($container, DenyAllWorkflowUiAccessChecker::class, $hostPolicyAlias, $hostPolicy));

                return;
            }

            throw new InvalidConfigurationException('nowo_workflow requires symfony/security-bundle (security.authorization_checker), or set nowo_workflow.security.allow_unauthenticated: true (dev/demo only — never in production), or set nowo_workflow.security.access_checker to a custom service id.');
        }

        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $this->wire($container, $this->configured($container, $accessCheckerId, $hostPolicyAlias, $hostPolicy));

            return;
        }

        if ($allowUnauthenticated || !$hasSecurity) {
            $this->wire($container, $this->builtIn($container, AllowAllWorkflowUiAccessChecker::class, $hostPolicyAlias, $hostPolicy));

            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $container->getParameter('nowo_workflow.security.access_roles');

        $container->register(RoleBasedWorkflowUiAccessChecker::class, RoleBasedWorkflowUiAccessChecker::class)
            ->setArgument('$requiredRoles', $accessRoles)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->setPublic(false);

        $this->wire($container, $this->builtIn($container, RoleBasedWorkflowUiAccessChecker::class, $hostPolicyAlias, $hostPolicy));
    }

    /**
     * A built-in service behind both ports (the host's own 1.14 alias, when there is one, keeps the 1.14 port; a host
     * decorator of the 1.14 port wraps the built-in).
     *
     * @param class-string $builtIn
     */
    private function builtIn(ContainerBuilder $container, string $builtIn, bool $hostPolicyAlias, bool $hostPolicy): string
    {
        $this->aliasLegacy($container, $builtIn);
        if (!$hostPolicyAlias) {
            $container->setAlias(WorkflowUiAccessPolicyInterface::class, $builtIn)->setPublic(false);
        }

        if (!$this->isDecorated($container, WorkflowUiAccessCheckerInterface::class)) {
            return self::MODE_POLICY;
        }

        // A host decorator of the deprecated port always runs: alone over the built-in (exactly 1.13), with the host's
        // 1.14 service or 1.14 decorator otherwise.
        return $hostPolicy ? $this->both($this->decoratorsOf($container, WorkflowUiAccessCheckerInterface::class)) : self::MODE_LEGACY;
    }

    /**
     * The service named by `security.access_checker`.
     */
    private function configured(ContainerBuilder $container, string $id, bool $hostPolicyAlias, bool $hostPolicy): string
    {
        $class = $this->classOf($container, $id);

        if ($class === null) {
            $this->assertPolicyDecoratorHasAService($container, $id, $hostPolicyAlias);
            // Unknown class (factory, synthetic, missing service): the 1.13 wiring (the subscriber's typed argument
            // still refuses a service of the wrong type), without a deprecation.
            $container->setAlias(WorkflowUiAccessCheckerInterface::class, $id)->setPublic(false);

            return $hostPolicy ? $this->both([$id]) : self::MODE_LEGACY;
        }

        $implementsPolicy = is_a($class, WorkflowUiAccessPolicyInterface::class, true);
        $implementsLegacy = is_a($class, WorkflowUiAccessCheckerInterface::class, true);

        if (!$implementsPolicy && !$implementsLegacy) {
            throw new LogicException(sprintf('nowo_workflow.security.access_checker "%s" (%s) must implement "%s".', $id, $class, WorkflowUiAccessPolicyInterface::class));
        }

        if ($implementsPolicy && $hostPolicyAlias && $this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class) !== $this->resolveAlias($container, $id)) {
            // Audit NF2-4a: never replace the host's own alias of the 1.14 port silently.
            throw new LogicException(sprintf('"%s" is aliased to "%s" by the application and nowo_workflow.security.access_checker names "%s": keep only one of them.', WorkflowUiAccessPolicyInterface::class, $this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class), $id));
        }

        if ($implementsLegacy) {
            $this->aliasLegacy($container, $id);
        } elseif ($this->decoratorsOf($container, WorkflowUiAccessCheckerInterface::class, true) !== []) {
            // Audit NF2-4b: a decorator of the deprecated port would be dropped (or wrap nothing): never silently. A
            // decorator registered with on_invalid: ignore asked to be dropped when there is nothing to decorate, so
            // it is (audit NF3-3).
            throw new LogicException(sprintf('"%s" decorates the deprecated "%s", but nowo_workflow.security.access_checker "%s" (%s) does not implement it: decorate "%s" instead.', implode('", "', $this->decoratorsOf($container, WorkflowUiAccessCheckerInterface::class, true)), WorkflowUiAccessCheckerInterface::class, $id, $class, WorkflowUiAccessPolicyInterface::class));
        } else {
            // Without the alias, Symfony removes the decorators registered with on_invalid: ignore (audit NF3-3).
            // The deprecated alias is registered only for a class that implements that port.
            $container->removeAlias(WorkflowUiAccessCheckerInterface::class);
        }

        if (!$implementsPolicy) {
            $this->assertPolicyDecoratorHasAService($container, $id, $hostPolicyAlias);
            trigger_deprecation(Configuration::PACKAGE, '1.14', 'The Workflow UI access checker "%s" (%s) implements only "%s"; implement "%s" instead (the deprecated interface is removed in 2.0).', $id, $class, WorkflowUiAccessCheckerInterface::class, WorkflowUiAccessPolicyInterface::class);

            return $hostPolicy ? $this->both([$id]) : self::MODE_LEGACY;
        }

        $container->setAlias(WorkflowUiAccessPolicyInterface::class, $id)->setPublic(false);

        return $implementsLegacy && $this->isDecorated($container, WorkflowUiAccessCheckerInterface::class)
            ? $this->both($this->decoratorsOf($container, WorkflowUiAccessCheckerInterface::class))
            : self::MODE_POLICY;
    }

    /**
     * A decorator of the 1.14 port needs a 1.14 service to wrap: with an access_checker on the deprecated port only (or
     * of an unknown class) and no host alias of the 1.14 port, it would wrap the fail-closed deny-all default.
     */
    private function assertPolicyDecoratorHasAService(ContainerBuilder $container, string $id, bool $hostPolicyAlias): void
    {
        if (!$hostPolicyAlias && $this->isDecorated($container, WorkflowUiAccessPolicyInterface::class)) {
            throw new LogicException(sprintf('"%s" decorates "%s", but nowo_workflow.security.access_checker "%s" does not implement it: implement "%s" on that service (or alias the interface to a service that does).', implode('", "', $this->decoratorsOf($container, WorkflowUiAccessPolicyInterface::class)), WorkflowUiAccessPolicyInterface::class, $id, WorkflowUiAccessPolicyInterface::class));
        }
    }

    /**
     * Both ports are asked (both must grant): a deprecation names the deprecated-port service(s) to migrate.
     *
     * @param list<string> $legacyServices
     */
    private function both(array $legacyServices): string
    {
        trigger_deprecation(Configuration::PACKAGE, '1.14', 'The Workflow UI access is decided by both "%s" and the deprecated "%s" (both must grant) because of the deprecated-port service(s) "%s"; migrate them to "%s" (the deprecated interface is removed in 2.0).', WorkflowUiAccessPolicyInterface::class, WorkflowUiAccessCheckerInterface::class, implode('", "', $legacyServices), WorkflowUiAccessPolicyInterface::class);

        return self::MODE_BOTH;
    }

    private function aliasLegacy(ContainerBuilder $container, string $id): void
    {
        $container->setAlias(WorkflowUiAccessCheckerInterface::class, $id)
            ->setPublic(false)
            ->setDeprecated(Configuration::PACKAGE, '1.14', 'The "%alias_id%" service alias is deprecated, use "' . WorkflowUiAccessPolicyInterface::class . '" instead (removed in 2.0).');
    }

    /**
     * Tells the subscriber which port(s) to ask.
     */
    private function wire(ContainerBuilder $container, string $mode): void
    {
        $container->setParameter(self::MODE_PARAMETER, $mode);

        if (!$container->hasDefinition(WorkflowUiAccessSubscriber::class)) {
            return;
        }

        $container->getDefinition(WorkflowUiAccessSubscriber::class)
            ->setArgument('$accessChecker', $mode === self::MODE_POLICY ? null : new Reference(WorkflowUiAccessCheckerInterface::class))
            ->setArgument('$accessPolicy', $mode === self::MODE_LEGACY ? null : new Reference(WorkflowUiAccessPolicyInterface::class))
            ->setArgument('$tokenStorage', $mode === self::MODE_LEGACY ? null : new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE));
    }

    /**
     * True when the host aliased (or defined) the 1.14 port itself, i.e. it no longer resolves to a built-in.
     */
    private function isHostPolicyAlias(ContainerBuilder $container): bool
    {
        if (!$container->hasAlias(WorkflowUiAccessPolicyInterface::class)) {
            return $container->hasDefinition(WorkflowUiAccessPolicyInterface::class);
        }

        return !in_array($this->resolveAlias($container, WorkflowUiAccessPolicyInterface::class), self::BUILT_INS, true);
    }

    private function isDecorated(ContainerBuilder $container, string $port): bool
    {
        return $this->decoratorsOf($container, $port) !== [];
    }

    /**
     * @param bool $requiredOnly skip the decorators registered with on_invalid: ignore (dropped by Symfony when the
     *                           decorated service does not exist)
     *
     * @return list<string> ids of the definitions that decorate the port
     */
    private function decoratorsOf(ContainerBuilder $container, string $port, bool $requiredOnly = false): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $decorated = $definition->getDecoratedService();
            if (($decorated[0] ?? null) !== $port) {
                continue;
            }
            if ($requiredOnly && ($decorated[3] ?? ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE) === ContainerInterface::IGNORE_ON_INVALID_REFERENCE) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
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
     * @return class-string|null null when the class cannot be known before the container is compiled
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

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
