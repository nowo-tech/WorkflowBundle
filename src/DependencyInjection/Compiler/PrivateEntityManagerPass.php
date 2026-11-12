<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Nowo\WorkflowBundle\Audit\AuditMiddleware;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Doctrine\WorkflowManagerRegistry;
use ReflectionMethod;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\RegisterMappingsPass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function interface_exists;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trigger_deprecation;

/**
 * Private entity manager wiring (1.14.0, REQ-ARCH-001 rule 6):
 *
 * - fails the build when `nowo_workflow.doctrine.entity_manager` names a manager DoctrineBundle does not know (a
 *   mistyped name included), or a manager that does not map the bundle entities;
 * - without `doctrine.entity_manager`, derives the source manager with the 1.13 rule (audit NF2-1): the first manager
 *   of `doctrine.entity_managers`, in order, whose mapping driver chain has a driver for the bundle namespace — the
 *   manager `getManagerForClass()` returned in 1.13 (e.g. the `default` manager the bundle mapping lands on when the
 *   application's default manager has another name). The name is given to {@see WorkflowEntityManager} so its
 *   source and the rollback middleware use the same manager. Mappings that a host {@see RegisterMappingsPass} (e.g.
 *   DoctrineOrmMappingsPass) adds later in the build are taken into account (audit NF2-3). When a chain cannot be
 *   inspected, nothing is derived and the private manager falls back to `getManagerForClass()` at runtime; the build
 *   fails only when every chain was inspected and none maps the bundle entities. A derived manager that is not the
 *   default one triggers a deprecation asking to set `doctrine.entity_manager`;
 * - attaches {@see PrivateEntityManagerMiddleware} only to the connection of that source manager: the host's other
 *   connections are never wrapped. When the manager is left to the runtime rule, it goes on the connection of every
 *   manager that rule may pick, in order (audit NF3-1).
 *
 * Runs before DoctrineBundle's MiddlewaresPass.
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /** Container parameter holding `nowo_workflow.doctrine.entity_manager` (null = derived, see the class comment). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_workflow.doctrine.entity_manager';

    /** DoctrineBundle's ORM mapping driver chain id pattern. */
    private const CHAIN_PATTERN = 'doctrine.orm.%s_metadata_driver';

    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (no replacement after a rollback, see docs/CONFIGURATION.md).
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WorkflowEntityManager::class)) {
            $this->tagAuditMiddleware($container, []);

            return;
        }

        $managers   = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers   = is_array($managers) ? $managers : [];
        $pending    = $this->pendingMappings($container);
        $configured = $container->hasParameter(self::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(self::ENTITY_MANAGER_PARAMETER) : null;

        if (is_string($configured) && $configured !== '') {
            if (!isset($managers[$configured])) {
                throw new InvalidConfigurationException(sprintf('nowo_workflow.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $configured, implode('", "', array_keys($managers))));
            }
            if ($this->mapsTheEntities($container, $configured, $pending) === false) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_workflow.doctrine.entity_manager) does not map %s. Map the entities on that manager (e.g. from the shipped XML) or remove the option.', $configured, WorkflowManagerRegistry::ENTITY_NAMESPACE));
            }
            $candidates = [$configured];
        } else {
            $manager = $this->derive($container, array_keys($managers), $pending);
            if ($manager !== null) {
                $container->getDefinition(WorkflowEntityManager::class)->setArgument(1, $manager);
                $candidates = [$manager];
            } else {
                // A chain could not be inspected: the private manager uses getManagerForClass() at runtime (1.13 rule),
                // which may return any manager not proven to not map the entities (audit NF3-1).
                $candidates = $this->runtimeCandidates($container, array_keys($managers), $pending);
                if ($candidates === []) {
                    $default    = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
                    $candidates = [is_string($default) && $default !== '' ? $default : 'default'];
                }
            }
        }

        $connections = [];
        foreach ($candidates as $manager) {
            $connection               = $this->connectionOf($container, $manager);
            $connections[$connection] = $connection;
        }
        $this->tagAuditMiddleware($container, $connections);

        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $definition = $container->getDefinition(PrivateEntityManagerMiddleware::class)->clearTag('doctrine.middleware');
        foreach ($connections as $connection) {
            $definition->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }

    /**
     * Since 1.15 (audit NO L1): the audit middleware observes only the connections of the definitions' manager and of
     * the application's default manager (where WorkflowApplicator persists the subject), never every connection.
     *
     * @param array<string, string> $connections
     */
    private function tagAuditMiddleware(ContainerBuilder $container, array $connections): void
    {
        if (!$container->hasDefinition(AuditMiddleware::class)) {
            return;
        }
        $default               = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default               = $this->connectionOf($container, is_string($default) && $default !== '' ? $default : 'default');
        $connections[$default] = $default;

        $definition = $container->getDefinition(AuditMiddleware::class)->clearTag('doctrine.middleware');
        foreach ($connections as $connection) {
            $definition->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }

    /**
     * The DBAL connection of a manager: DoctrineBundle passes it as the manager's first argument; the default
     * connection when the manager definition does not say.
     */
    private function connectionOf(ContainerBuilder $container, string $manager): string
    {
        $id       = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }
        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * The managers `getManagerForClass()` may return at runtime when a chain could not be inspected (audit NF3-1):
     * in order, every manager not proven to not map the bundle entities, up to and including the first one proven to
     * map them. The rollback middleware goes on the connection of each one, so it is on the source connection
     * whichever of them the runtime rule picks (a rollback on another of them only replaces the private manager once
     * more).
     *
     * @param list<int|string> $managers
     * @param array<string, list<string>> $pending
     *
     * @return list<string>
     */
    private function runtimeCandidates(ContainerBuilder $container, array $managers, array $pending): array
    {
        $candidates = [];
        foreach ($managers as $manager) {
            $maps = $this->mapsTheEntities($container, (string) $manager, $pending);
            if ($maps === false) {
                continue;
            }
            $candidates[] = (string) $manager;
            if ($maps === true) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * The 1.13 rule: the first manager, in order, that maps the bundle entities; null when a chain cannot be inspected
     * before such a manager is found.
     *
     * @param list<int|string> $managers
     * @param array<string, list<string>> $pending
     *
     * @throws InvalidConfigurationException when every chain was inspected and none maps the bundle entities
     */
    private function derive(ContainerBuilder $container, array $managers, array $pending): ?string
    {
        foreach ($managers as $manager) {
            $maps = $this->mapsTheEntities($container, (string) $manager, $pending);
            if ($maps === null) {
                return null;
            }
            if ($maps) {
                $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
                if ($default !== null && $default !== (string) $manager) {
                    trigger_deprecation(Configuration::PACKAGE, '1.14', 'The workflow definitions are stored through the Doctrine entity manager "%s", which is not the default one ("%s"): set nowo_workflow.doctrine.entity_manager: %s explicitly.', (string) $manager, is_string($default) ? $default : '', (string) $manager);
                }

                return (string) $manager;
            }
        }

        if ($managers === []) {
            return null;
        }

        throw new InvalidConfigurationException(sprintf('No Doctrine entity manager maps %s (inspected: "%s"). Keep doctrine.register_mapping: true, map the entities on a manager, or set nowo_workflow.doctrine.entity_manager to the manager that maps them.', WorkflowManagerRegistry::ENTITY_NAMESPACE, implode('", "', $managers)));
    }

    /**
     * Whether the manager's mapping driver chain (plus the mappings pending host passes add to it) has a driver for
     * the bundle namespace; null when the chain cannot be inspected.
     *
     * @param array<string, list<string>> $pending
     */
    private function mapsTheEntities(ContainerBuilder $container, string $manager, array $pending): ?bool
    {
        foreach ($pending[$manager] ?? [] as $namespace) {
            if ($this->covers($namespace)) {
                return true;
            }
        }

        $chainId = sprintf(self::CHAIN_PATTERN, $manager);
        if (!$container->hasDefinition($chainId)) {
            return null;
        }

        foreach ($container->getDefinition($chainId)->getMethodCalls() as [$method, $arguments]) {
            if ($method === 'addDriver' && is_string($arguments[1] ?? null) && $this->covers($arguments[1])) {
                return true;
            }
        }

        return false;
    }

    private function covers(string $namespace): bool
    {
        return str_starts_with(WorkflowManagerRegistry::ENTITY_NAMESPACE . '\\', rtrim($namespace, '\\') . '\\');
    }

    /**
     * Namespaces the registered (not yet run) ORM {@see RegisterMappingsPass} instances will add, per manager.
     *
     * @return array<string, list<string>>
     */
    private function pendingMappings(ContainerBuilder $container): array
    {
        $pattern = self::CHAIN_PATTERN;
        // Bound to RegisterMappingsPass below: its mapping settings are protected.
        $read = static function (RegisterMappingsPass $pass, ContainerBuilder $container) use ($pattern): ?array {
            if ($pass->driverPattern !== $pattern || !$pass->enabled($container)) { // @phpstan-ignore property.protected, method.protected (bound to RegisterMappingsPass)
                return null;
            }
            foreach ($pass->managerParameters as $parameter) { // @phpstan-ignore property.protected (bound to RegisterMappingsPass)
                $name = $container->hasParameter($parameter) ? $container->getParameter($parameter) : null;
                if (is_string($name) && $name !== '') {
                    return [$name, $pass->namespaces]; // @phpstan-ignore property.protected (bound to RegisterMappingsPass)
                }
            }

            return null;
        };
        $read = Closure::bind($read, null, RegisterMappingsPass::class);

        $pending = [];
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            if (!$pass instanceof RegisterMappingsPass) {
                continue;
            }
            $found = $read($pass, $container);
            if ($found === null) {
                continue;
            }
            foreach ($found[1] as $namespace) {
                if (is_string($namespace)) {
                    $pending[$found[0]][] = $namespace;
                }
            }
        }

        return $pending;
    }
}
