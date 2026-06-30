<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for database-driven workflow definitions and runtime execution.
 */
class NowoWorkflowBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new NowoWorkflowExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
