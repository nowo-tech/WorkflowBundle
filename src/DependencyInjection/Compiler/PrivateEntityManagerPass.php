<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use ReflectionMethod;
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
use function sprintf;

/**
 * Private entity manager wiring (1.14.0, REQ-ARCH-001 rule 6):
 *
 * - fails the build when `nowo_workflow.doctrine.entity_manager` names a manager DoctrineBundle does not know (a
 *   mistyped name included);
 * - attaches {@see PrivateEntityManagerMiddleware} only to the connection of the source manager of
 *   {@see WorkflowEntityManager} (the configured manager, else the default one): the host's other connections are
 *   never wrapped.
 *
 * Runs before DoctrineBundle's MiddlewaresPass.
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /** Container parameter holding `nowo_workflow.doctrine.entity_manager` (null = the default manager). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_workflow.doctrine.entity_manager';

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
            return;
        }

        $configured = $container->hasParameter(self::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(self::ENTITY_MANAGER_PARAMETER) : null;
        if (is_string($configured) && $configured !== '') {
            $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
            $managers = is_array($managers) ? $managers : [];
            if (!isset($managers[$configured])) {
                throw new InvalidConfigurationException(sprintf('nowo_workflow.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $configured, implode('", "', array_keys($managers))));
            }
        }

        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $manager = is_string($configured) && $configured !== '' ? $configured : ($container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null);
        $id      = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) && $manager !== '' ? $manager : 'default');
        // DoctrineBundle passes the connection as the manager's first argument.
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
