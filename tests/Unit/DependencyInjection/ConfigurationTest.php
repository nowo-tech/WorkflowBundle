<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('default', $config['connection']);
        self::assertSame('workflow_', $config['table_prefix']);
        self::assertSame('/workflow', $config['ui']['path']);
        self::assertSame('en', $config['ui']['default_locale']);
        self::assertSame(['en', 'es', 'fr', 'it'], $config['ui']['locales']);
    }

    public function testCustomUiConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => [
                'path'           => '/admin/workflows',
                'default_locale' => 'es',
                'locales'        => ['es', 'en'],
            ],
        ]]);

        self::assertSame('/admin/workflows', $config['ui']['path']);
        self::assertSame(['es', 'en'], $config['ui']['locales']);
    }
}
