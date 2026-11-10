<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\FrameworkWorkflowNamesPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class FrameworkWorkflowNamesPassTest extends TestCase
{
    public function testCollectsSortedUniqueFrameworkWorkflowNames(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FrameworkWorkflowNamesPass::PARAMETER, []);
        $container->setDefinition('state_machine.order', (new Definition())->addTag('workflow', ['name' => 'order']));
        $container->setDefinition('workflow.blog', (new Definition())->addTag('workflow', ['name' => 'blog'])->addTag('workflow', ['name' => 'order']));
        $container->setDefinition('workflow.unnamed', (new Definition())->addTag('workflow')->addTag('workflow', ['name' => '']));

        (new FrameworkWorkflowNamesPass())->process($container);

        self::assertSame(['blog', 'order'], $container->getParameter(FrameworkWorkflowNamesPass::PARAMETER));
    }

    public function testEmptyListWithoutFrameworkWorkflows(): void
    {
        $container = new ContainerBuilder();

        (new FrameworkWorkflowNamesPass())->process($container);

        self::assertSame([], $container->getParameter(FrameworkWorkflowNamesPass::PARAMETER));
    }
}
