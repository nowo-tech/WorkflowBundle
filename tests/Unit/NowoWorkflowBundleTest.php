<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit;

use Nowo\WorkflowBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\FrameworkWorkflowNamesPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoWorkflowBundleTest extends TestCase
{
    public function testBuildRegistersCompilerPasses(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new NowoWorkflowBundle();
        $bundle->build($container);

        $passes    = $container->getCompilerPassConfig()->getPasses();
        $foundTwig = false;
        $foundSec  = false;
        $foundCsrf = false;
        $foundName = false;

        foreach ($passes as $pass) {
            if ($pass instanceof TwigPathsPass) {
                $foundTwig = true;
            }
            if ($pass instanceof WorkflowUiSecurityPass) {
                $foundSec = true;
            }
            if ($pass instanceof CsrfProtectionPass) {
                $foundCsrf = true;
            }
            if ($pass instanceof FrameworkWorkflowNamesPass) {
                $foundName = true;
            }
        }

        self::assertTrue($foundTwig);
        self::assertTrue($foundSec);
        self::assertTrue($foundCsrf);
        self::assertTrue($foundName);
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
