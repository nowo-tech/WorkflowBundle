<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function implode;
use function sprintf;

/**
 * Fails the build when the application still wires a port removed in 2.0 (REQ-ARCH-001): a service registered under,
 * aliased to or decorating one of the removed ids would otherwise be dropped or ignored without a word (a decorator of
 * the removed UI access port could even widen access by disappearing). The message names the service(s) and the port
 * that replaces the removed one.
 *
 * The ids are strings: the interfaces no longer exist.
 *
 * Runs before optimization (host definitions loaded, decorators not applied yet), before the other bundle passes.
 *
 * @internal
 */
final class RemovedPortsPass implements CompilerPassInterface
{
    /** Removed in 2.0 (deprecated since 1.15): the registry port, replaced by WorkflowRuntimeInterface. */
    public const REGISTRY = 'Nowo\\WorkflowBundle\\Contract\\WorkflowRegistryInterface';

    /** Removed in 2.0 (deprecated since 1.14): the request-based UI access port, replaced by WorkflowUiAccessPolicyInterface. */
    public const UI_ACCESS_CHECKER = 'Nowo\\WorkflowBundle\\Contract\\WorkflowUiAccessCheckerInterface';

    /** Removed in 2.0 (deprecated since 1.19): the entity versioning port, replaced by WorkflowVersionManagerInterface. */
    public const VERSIONING = 'Nowo\\WorkflowBundle\\Contract\\WorkflowVersioningInterface';

    /**
     * Removed id => [deprecated since, what to do instead].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REMOVED_PORTS = [
        self::REGISTRY => [
            '1.15',
            'Call Nowo\\WorkflowBundle\\Contract\\WorkflowRuntimeInterface instead, and move custom logic to a workflow.guard listener or to your own WorkflowRuntimeInterface service',
        ],
        self::UI_ACCESS_CHECKER => [
            '1.14',
            'Implement Nowo\\WorkflowBundle\\Contract\\WorkflowUiAccessPolicyInterface instead (set nowo_workflow.security.access_checker, alias the interface or decorate it)',
        ],
        self::VERSIONING => [
            '1.19',
            'Decorate or replace Nowo\\WorkflowBundle\\Contract\\WorkflowVersionManagerInterface instead',
        ],
    ];

    /**
     * @throws LogicException when a removed port is still registered, aliased or decorated
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED_PORTS as $port => [$since, $instead]) {
            $services = [];
            if ($container->hasAlias($port)) {
                $services[] = (string) $container->getAlias($port);
            } elseif ($container->hasDefinition($port)) {
                $services[] = $port;
            }
            foreach ($container->getDefinitions() as $id => $definition) {
                if (($definition->getDecoratedService()[0] ?? null) === $port) {
                    $services[] = $id;
                }
            }

            if ($services !== []) {
                throw new LogicException(sprintf('"%s" was removed in 2.0 (deprecated since %s), but the application still registers, aliases or decorates it: "%s". %s. See UPGRADING.md "To 2.0.0".', $port, $since, implode('", "', $services), $instead));
            }
        }
    }
}
