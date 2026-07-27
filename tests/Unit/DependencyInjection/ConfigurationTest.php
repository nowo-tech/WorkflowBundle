<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('default', $config['connection']);
        self::assertSame('workflow_', $config['table_prefix']);
        self::assertSame('/workflow', $config['ui']['path']);
        self::assertSame('@NowoWorkflowBundle/layout.html.twig', $config['ui']['layout_template']);
        self::assertSame('bootstrap5', $config['ui']['css_framework']);
        self::assertSame('bootstrap-icons', $config['ui']['icon_set']);
        self::assertSame(20, $config['ui']['list_page_size']);
        self::assertSame('en', $config['ui']['default_locale']);
        self::assertSame(['en', 'es', 'fr', 'it'], $config['ui']['locales']);
        self::assertSame(['ROLE_ADMIN'], $config['ui']['required_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
    }

    #[Test]
    public function testCustomUiConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => [
                'path'            => '/admin/workflows',
                'layout_template' => 'base.html.twig',
                'css_framework'   => 'tailwind',
                'icon_set'        => 'none',
                'default_locale'  => 'es',
                'locales'         => ['es', 'en'],
            ],
        ]]);

        self::assertSame('/admin/workflows', $config['ui']['path']);
        self::assertSame('base.html.twig', $config['ui']['layout_template']);
        self::assertSame('tailwind', $config['ui']['css_framework']);
        self::assertSame('none', $config['ui']['icon_set']);
        self::assertSame(['es', 'en'], $config['ui']['locales']);
    }

    #[Test]
    public function testBootstrapAliasNormalizesToBootstrap5(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => ['css_framework' => 'bootstrap'],
        ]]);

        self::assertSame('bootstrap5', $config['ui']['css_framework']);
    }

    #[Test]
    public function testTablerAliasNormalizesToBootstrap5(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => ['css_framework' => 'tabler'],
        ]]);

        self::assertSame('bootstrap5', $config['ui']['css_framework']);
    }

    #[Test]
    public function testUiRequiredRolesAliasSecurityAccessRoles(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => ['required_roles' => ['ROLE_WORKFLOW']],
        ]]);

        self::assertSame(['ROLE_WORKFLOW'], $config['security']['access_roles']);
        self::assertSame(['ROLE_WORKFLOW'], $config['ui']['required_roles']);
    }

    #[Test]
    public function testInvalidCssFrameworkRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => ['css_framework' => 'material'],
        ]]);
    }
}
