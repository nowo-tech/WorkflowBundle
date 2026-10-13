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
