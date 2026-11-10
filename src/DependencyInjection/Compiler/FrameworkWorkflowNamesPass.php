<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function in_array;
use function is_string;
use function sort;

/**
 * Collects the names of the workflows configured under framework.workflows (services tagged "workflow") into the
 * nowo_workflow.framework_workflow_names parameter, so DatabaseWorkflowRegistry can refuse a database slug that would
 * dispatch the same workflow.<name>.* events as a framework workflow.
 */
final class FrameworkWorkflowNamesPass implements CompilerPassInterface
{
    public const PARAMETER = 'nowo_workflow.framework_workflow_names';

    /**
     * Stores the sorted, unique framework workflow names in the parameter.
     */
    public function process(ContainerBuilder $container): void
    {
        $names = [];
        foreach ($container->findTaggedServiceIds('workflow') as $tags) {
            foreach ($tags as $attributes) {
                $name = $attributes['name'] ?? null;
                if (is_string($name) && $name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        sort($names);
        $container->setParameter(self::PARAMETER, $names);
    }
}
