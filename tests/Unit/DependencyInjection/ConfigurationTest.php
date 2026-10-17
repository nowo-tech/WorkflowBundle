<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertArrayNotHasKey('enabled', $config);
        self::assertSame('default', $config['connection']);
        self::assertSame('workflow_', $config['table_prefix']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/workflow', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame(Configuration::DEFAULT_TEMPLATES, $config['web_ui']['templates']);
        self::assertSame(20, $config['web_ui']['list_page_size']);
        self::assertSame('en', $config['web_ui']['default_locale']);
        self::assertSame(['en', 'es', 'fr', 'it'], $config['web_ui']['locales']);
        self::assertNull($config['ui']['css_framework']);
        self::assertNull($config['ui']['icon_set']);
        self::assertArrayNotHasKey('path', $config['ui']);
        self::assertArrayNotHasKey('layout_template', $config['ui']);
        self::assertSame(['ROLE_ADMIN'], $config['ui']['required_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull(Configuration::legacyLayout($config));
    }

    #[Test]
    public function testCanonicalWebUiConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => [
                'enabled'         => false,
                'path_prefix'     => '/admin/workflows',
                'layout_template' => 'layouts/admin.html.twig',
                'templates'       => ['index' => 'admin/workflow/index.html.twig'],
                'list_page_size'  => 50,
                'default_locale'  => 'es',
                'locales'         => ['es', 'en'],
            ],
        ]]);

        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('/admin/workflows', $config['web_ui']['path_prefix']);
        self::assertSame('layouts/admin.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('admin/workflow/index.html.twig', $config['web_ui']['templates']['index']);
        self::assertSame(Configuration::DEFAULT_TEMPLATES['show'], $config['web_ui']['templates']['show']);
        self::assertSame(50, $config['web_ui']['list_page_size']);
        self::assertSame(['es', 'en'], $config['web_ui']['locales']);
    }

    #[Test]
    public function testDeprecatedUiKeysMapToWebUi(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled' => false,
            'ui'      => [
                'path'            => '/admin/workflows',
                'layout_template' => 'base.html.twig',
                'css_framework'   => 'tailwind',
                'icon_set'        => 'none',
                'list_page_size'  => 5,
                'default_locale'  => 'es',
                'locales'         => ['es', 'en'],
            ],
        ]]);

        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('/admin/workflows', $config['web_ui']['path_prefix']);
        self::assertSame(Configuration::LEGACY_LAYOUT_BRIDGE, $config['web_ui']['layout_template']);
        self::assertSame('base.html.twig', Configuration::legacyLayout($config));
        self::assertSame(5, $config['web_ui']['list_page_size']);
        self::assertSame('es', $config['web_ui']['default_locale']);
        self::assertSame(['es', 'en'], $config['web_ui']['locales']);
        self::assertSame('tailwind', $config['ui']['css_framework']);
        self::assertSame('none', $config['ui']['icon_set']);
    }

    #[Test]
    public function testCanonicalKeysWinOverDeprecatedAliases(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled' => false,
            'ui'      => ['path' => '/old', 'layout_template' => 'base.html.twig'],
            'web_ui'  => ['enabled' => true, 'path_prefix' => '/new', 'layout_template' => null],
        ]]);

        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/new', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
    }

    #[Test]
    public function testRemovedDefaultLayoutMapsToUiKitLayout(): void
    {
        foreach ([Configuration::REMOVED_DEFAULT_LAYOUT, '', null] as $legacy) {
            $config = (new Processor())->processConfiguration(new Configuration(), [[
                'ui' => ['layout_template' => $legacy],
            ]]);

            self::assertNull($config['web_ui']['layout_template']);
            self::assertNull(Configuration::legacyLayout($config));
        }
    }

    #[Test]
    public function testDeprecatedKeysAreFlagged(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $tree);
        $enabled = $tree->getChildren()['enabled'];
        self::assertInstanceOf(BaseNode::class, $enabled);
        self::assertTrue($enabled->isDeprecated());
        self::assertSame('1.9', $enabled->getDeprecation('enabled', 'nowo_workflow')['version']);

        $ui = $tree->getChildren()['ui'];
        self::assertInstanceOf(ArrayNode::class, $ui);
        foreach (['path', 'layout_template', 'css_framework', 'icon_set', 'list_page_size', 'default_locale', 'locales', 'required_roles'] as $key) {
            $node = $ui->getChildren()[$key];
            self::assertInstanceOf(BaseNode::class, $node);
            self::assertTrue($node->isDeprecated(), $key);
            $deprecation = $node->getDeprecation($key, 'nowo_workflow.ui');
            self::assertSame('nowo-tech/workflow-bundle', $deprecation['package']);
            self::assertStringContainsString('nowo_workflow.ui.' . $key, $deprecation['message']);
        }

        $webUi = $tree->getChildren()['web_ui'];
        self::assertInstanceOf(ArrayNode::class, $webUi);
        foreach ($webUi->getChildren() as $node) {
            self::assertInstanceOf(BaseNode::class, $node);
            self::assertFalse($node->isDeprecated());
        }
    }

    #[Test]
    public function testMapDeprecatedKeysLeavesCanonicalConfigUntouched(): void
    {
        self::assertSame(['connection' => 'custom'], Configuration::mapDeprecatedKeys(['connection' => 'custom']));
        self::assertSame(['web_ui' => []], Configuration::mapDeprecatedKeys(['web_ui' => null]));
        self::assertSame(
            ['ui' => 'invalid', 'security' => 'invalid'],
            Configuration::mapDeprecatedKeys(['ui' => 'invalid', 'security' => 'invalid']),
        );
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
    public function testExplicitAccessRolesWinOverUiRequiredRoles(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'ui'       => ['required_roles' => ['ROLE_WORKFLOW']],
            'security' => ['access_roles' => ['ROLE_SUPER_ADMIN']],
        ]]);

        self::assertSame(['ROLE_SUPER_ADMIN'], $config['security']['access_roles']);
        self::assertSame(['ROLE_SUPER_ADMIN'], $config['ui']['required_roles']);
    }

    #[Test]
    public function emptyAccessRolesRejectedWhenAuthenticatedGateIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('access_roles');

        (new Processor())->processConfiguration(new Configuration(), [[
            'security' => [
                'access_roles' => [],
            ],
        ]]);
    }

    #[Test]
    public function testInvalidCssFrameworkRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'ui' => ['css_framework' => 'material'],
        ]]);
    }

    #[Test]
    public function testEmptyAccessRolesAllowedWithUnauthenticatedMode(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'security' => ['access_roles' => [], 'allow_unauthenticated' => true],
        ]]);

        self::assertSame([], $config['security']['access_roles']);
    }

    #[Test]
    public function testEmptyAccessRolesAllowedWithCustomAccessChecker(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'security' => ['access_roles' => [], 'access_checker' => 'app.workflow_checker'],
        ]]);

        self::assertSame([], $config['security']['access_roles']);
        self::assertSame('app.workflow_checker', $config['security']['access_checker']);
    }

    #[Test]
    public function testEmptyAccessCheckerStringDoesNotBypassRoleRequirement(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'security' => ['access_roles' => ['', ''], 'access_checker' => ''],
        ]]);
    }

    #[Test]
    public function testEnvironmentsOptionIsDeprecated(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $tree);
        $environments = $tree->getChildren()['environments'];
        $connection   = $tree->getChildren()['connection'];
        self::assertInstanceOf(BaseNode::class, $environments);
        self::assertInstanceOf(BaseNode::class, $connection);

        self::assertTrue($environments->isDeprecated());
        $deprecation = $environments->getDeprecation('environments', 'nowo_workflow');
        self::assertSame('nowo-tech/workflow-bundle', $deprecation['package']);
        self::assertSame('1.8', $deprecation['version']);
        self::assertSame('The "nowo_workflow.environments" option is deprecated and has no effect; register the bundle only for the wanted environments in config/bundles.php instead.', $deprecation['message']);
        self::assertFalse($connection->isDeprecated());

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'environments' => ['prod'],
        ]]);
        self::assertSame(['prod'], $config['environments']);
    }
}
