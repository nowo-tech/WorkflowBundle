<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowRuntime;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function is_string;
use function sprintf;

/**
 * Wires the runtime port (1.15, REQ-ARCH-001 rule 3): the host's own {@see WorkflowRuntimeInterface} (alias or
 * definition, or a decorator of it) wins over the built-in {@see WorkflowRuntime}; its class must implement the port
 * (the build fails otherwise). The 1.x registry port it replaced was removed in 2.0 ({@see RemovedPortsPass} fails the
 * build when the application still wires it).
 *
 * Runs before optimization (host definitions loaded, decorators not applied yet).
 */
final class WorkflowRuntimePass implements CompilerPassInterface
{
    /** Container parameter: "built-in" or "host" (debugging and tests). */
    public const RUNTIME_PARAMETER = 'nowo_workflow.runtime.port';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WorkflowRuntime::class) || !$container->hasDefinition(DatabaseWorkflowRegistry::class)) {
            return;
        }

        $runtimeId = $this->resolveAlias($container, WorkflowRuntimeInterface::class);
        $host      = $runtimeId !== WorkflowRuntime::class || $this->decoratorsOf($container, WorkflowRuntimeInterface::class) !== [];
        $class     = $runtimeId !== WorkflowRuntime::class ? $this->classOf($container, $runtimeId) : null;
        if ($class !== null) {
            // Never load the class directly: one that still implements the removed registry port cannot load.
            $reflection = $container->getReflectionClass($class, false);
            if (!$reflection instanceof ReflectionClass) {
                throw new LogicException(sprintf('"%s" is aliased to "%s", whose class "%s" cannot be loaded (it does not exist, or it still implements "%s", removed in 2.0). See UPGRADING.md "To 2.0.0".', WorkflowRuntimeInterface::class, $runtimeId, $class, RemovedPortsPass::REGISTRY));
            }
            if (!$reflection->implementsInterface(WorkflowRuntimeInterface::class)) {
                throw new LogicException(sprintf('"%s" is aliased to "%s" (%s), which does not implement it.', WorkflowRuntimeInterface::class, $runtimeId, $class));
            }
        }
        $container->setParameter(self::RUNTIME_PARAMETER, $host ? 'host' : 'built-in');
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
     * The class of a service definition, null when it cannot be known before compilation (factory, synthetic, no
     * definition). The class is not loaded here.
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

        return is_string($class) ? $class : null;
    }
}
