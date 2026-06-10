<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\WorkflowBundle\NowoWorkflowBundle;
use PHPUnit\Framework\TestCase;

final class BundleConfigurationTest extends TestCase
{
    public function testBundleExposesExtensionAlias(): void
    {
        $bundle    = new NowoWorkflowBundle();
        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('nowo_workflow', $extension->getAlias());
    }
}
