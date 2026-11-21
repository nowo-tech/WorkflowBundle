<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\FrameworkWorkflowNamesPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowRuntimePass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for database-driven workflow definitions and runtime execution.
 */
class NowoWorkflowBundle extends Bundle
{
    /**
     * Registers the removed-port check (2.0), Twig paths, Workflow UI security, CSRF protection, framework workflow
     * names and private entity manager compiler passes.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Since 2.0: first of all (before any pass can drop a definition), so a removed port the application still wires
        // or implements fails with its own message.
        $container->addCompilerPass(new RemovedPortsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new WorkflowUiSecurityPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new FrameworkWorkflowNamesPass());
        $container->addCompilerPass(new WorkflowRuntimePass());
        // Before DoctrineBundle's MiddlewaresPass (priority 0).
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * Returns the bundle extension (nowo_workflow alias).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoWorkflowExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
