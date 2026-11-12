<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowRuntime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function class_exists;
use function implode;
use function is_a;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * Wires the runtime ports (1.15, REQ-ARCH-001 rule 3), with the house rules of the 1.14 UI access ports:
 *
 * - the host's own {@see WorkflowRuntimeInterface} (alias or definition, or a decorator of it) wins over the built-in
 *   {@see WorkflowRuntime}; its class must implement the port (the build fails otherwise);
 * - the deprecated {@see WorkflowRegistryInterface} stays the SPI the built-in runtime asks (through
 *   `WorkflowApplicator`), so a host registry or a decorator of it keeps running exactly as in 1.14; a deprecation
 *   asks to migrate it;
 * - a host service aliased to `WorkflowRegistryInterface` whose class implements only `WorkflowRuntimeInterface`
 *   becomes the runtime port and the registry alias goes back to the built-in: the deprecated alias is kept only for
 *   a class that implements it;
 * - a class implementing neither port fails the build;
 * - with tenancy, a host registry or registry decorator fails the build (the runtime builds the subject's pinned
 *   tenant version itself and would never ask it).
 *
 * Runs before optimization (host definitions loaded, decorators not applied yet).
 */
final class WorkflowRuntimePass implements CompilerPassInterface
{
    /** Container parameter: "built-in" or "host" (debugging and tests). */
    public const RUNTIME_PARAMETER = 'nowo_workflow.runtime.port';

    /** Container parameter: "built-in" or "host" (a host registry or decorator of the deprecated SPI). */
    public const REGISTRY_PARAMETER = 'nowo_workflow.runtime.registry';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WorkflowRuntime::class) || !$container->hasDefinition(DatabaseWorkflowRegistry::class)) {
            return;
        }

        $registryId = $this->resolveAlias($container, WorkflowRegistryInterface::class);
        $registry   = $registryId === WorkflowRegistryInterface::class && !$container->hasDefinition($registryId) ? null : $registryId;
        if ($registry !== null && $registry !== DatabaseWorkflowRegistry::class) {
            $class = $this->classOf($container, $registry);
            if ($class !== null && !is_a($class, WorkflowRegistryInterface::class, true)) {
                if (!is_a($class, WorkflowRuntimeInterface::class, true)) {
                    throw new LogicException(sprintf('"%s" is aliased to "%s" (%s), which implements neither "%s" nor "%s".', WorkflowRegistryInterface::class, $registry, $class, WorkflowRuntimeInterface::class, WorkflowRegistryInterface::class));
                }
                // A runtime-port class under the old name: it becomes the runtime, the SPI goes back to the built-in.
                $container->setAlias(WorkflowRuntimeInterface::class, $registry)->setPublic(false);
                $container->setAlias(WorkflowRegistryInterface::class, DatabaseWorkflowRegistry::class)->setPublic(false);
                $registry = DatabaseWorkflowRegistry::class;
            }
        }

        $runtimeId = $this->resolveAlias($container, WorkflowRuntimeInterface::class);
        $host      = $runtimeId !== WorkflowRuntime::class || $this->decoratorsOf($container, WorkflowRuntimeInterface::class) !== [];
        if ($runtimeId !== WorkflowRuntime::class) {
            $class = $this->classOf($container, $runtimeId);
            if ($class !== null && !is_a($class, WorkflowRuntimeInterface::class, true)) {
                throw new LogicException(sprintf('"%s" is aliased to "%s" (%s), which does not implement it.', WorkflowRuntimeInterface::class, $runtimeId, $class));
            }
        }
        $container->setParameter(self::RUNTIME_PARAMETER, $host ? 'host' : 'built-in');

        $legacy = [];
        if ($registry !== null && $registry !== DatabaseWorkflowRegistry::class) {
            $legacy[] = $registry;
        }
        $legacy = [...$legacy, ...$this->decoratorsOf($container, WorkflowRegistryInterface::class)];
        $container->setParameter(self::REGISTRY_PARAMETER, $legacy === [] ? 'built-in' : 'host');
        if ($legacy !== [] && $container->hasParameter('nowo_workflow.tenancy.enabled') && $container->getParameter('nowo_workflow.tenancy.enabled') === true) {
            // With tenancy the runtime builds the tenant's pinned version itself: a host registry would never be asked.
            throw new LogicException(sprintf('Tenancy (nowo_workflow.tenancy.tenant_resolver) cannot be combined with the host registry service(s) "%s" of the deprecated "%s": move that logic to "%s" or a workflow.guard listener.', implode('", "', $legacy), WorkflowRegistryInterface::class, WorkflowRuntimeInterface::class));
        }
        if ($legacy !== []) {
            trigger_deprecation(Configuration::PACKAGE, '1.15', 'The workflow registry service(s) "%s" replace or decorate "%s", which is deprecated as a port for hosts (removed in 2.0): call "%s" instead; the built-in runtime keeps asking your registry until then.', implode('", "', $legacy), WorkflowRegistryInterface::class, WorkflowRuntimeInterface::class);
        }
    }

    /**
     * @return list<string>
     */
    private function decoratorsOf(ContainerBuilder $container, string $port): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $decorated = $definition->getDecoratedService();
            if (($decorated[0] ?? null) === $port && ($decorated[3] ?? ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE) !== ContainerInterface::IGNORE_ON_INVALID_REFERENCE) {
                $ids[] = $id;
            }
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
     * @return class-string|null null when the class cannot be known before compilation
     */
    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
