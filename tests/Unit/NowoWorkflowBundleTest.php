<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoWorkflowBundleTest extends TestCase
{
    public function testBuildRegistersTwigPathsPass(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new NowoWorkflowBundle();
        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getPasses();
        $found  = false;

        foreach ($passes as $pass) {
            if ($pass instanceof TwigPathsPass) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found);
    }

    public function testGetContainerExtensionReturnsSameInstance(): void
    {
        $bundle    = new NowoWorkflowBundle();
        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('nowo_workflow', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
