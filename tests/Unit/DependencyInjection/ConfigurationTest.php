<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertFalse($config['runtime']['flush_after_apply'], '2.0: apply*() never flushes the application\'s manager by default');
        self::assertTrue($config['doctrine']['private_entity_manager']);
        self::assertSame(['default' => 'lock', 'workflows' => [], 'retry_after' => 1], $config['runtime']['concurrency']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/workflow', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame(Configuration::DEFAULT_TEMPLATES, $config['web_ui']['templates']);
        self::assertSame(20, $config['web_ui']['list_page_size']);
        self::assertSame('en', $config['web_ui']['default_locale']);
        self::assertSame(['en', 'es', 'fr', 'it'], $config['web_ui']['locales']);
        self::assertArrayNotHasKey('ui', $config);
        self::assertArrayNotHasKey('environments', $config);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
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

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function removedKeys(): iterable
    {
        yield 'enabled (1.9)' => [['enabled' => false], 'The "nowo_workflow.enabled" option was removed in 2.0 (deprecated since 1.9): use "nowo_workflow.web_ui.enabled"'];
        yield 'environments (1.8)' => [['environments' => ['prod']], 'The "nowo_workflow.environments" option was removed in 2.0 (deprecated since 1.8): remove it and register the bundle only for the wanted environments in config/bundles.php'];
        yield 'ui.* (1.9)' => [['ui' => ['path' => '/old']], 'The "nowo_workflow.ui" option was removed in 2.0 (deprecated since 1.9): use "nowo_workflow.web_ui.*"'];
        yield 'empty ui (1.9)' => [['ui' => null], 'The "nowo_workflow.ui" option was removed in 2.0'];
    }

    /**
     * 2.0: a key removed after its 1.x deprecation fails with its replacement and a pointer to UPGRADING.md.
     *
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('removedKeys')]
    public function aRemovedKeyFailsWithItsReplacement(array $config, string $message): void
    {
        try {
            (new Processor())->processConfiguration(new Configuration(), [$config]);
            self::fail('A removed key must fail.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertStringContainsString('See UPGRADING.md "To 2.0.0".', $exception->getMessage());
        }
    }

    #[Test]
    public function noNodeIsDeprecatedExceptThePrivateEntityManagerNoOp(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $tree);
        self::assertArrayNotHasKey('enabled', $tree->getChildren());
        self::assertArrayNotHasKey('environments', $tree->getChildren());
        self::assertArrayNotHasKey('ui', $tree->getChildren());

        $deprecated = [];
        $walk       = static function (BaseNode $node, string $path) use (&$walk, &$deprecated): void {
            if ($node->isDeprecated()) {
                $deprecated[] = $path;
            }
            if ($node instanceof ArrayNode) {
                foreach ($node->getChildren() as $name => $child) {
                    self::assertInstanceOf(BaseNode::class, $child);
                    $walk($child, $path . '.' . $name);
                }
            }
        };
        $walk($tree, 'nowo_workflow');

        self::assertSame(['nowo_workflow.doctrine.private_entity_manager'], $deprecated);
        $doctrine = $tree->getChildren()['doctrine'];
        self::assertInstanceOf(ArrayNode::class, $doctrine);
        $node = $doctrine->getChildren()['private_entity_manager'];
        self::assertInstanceOf(BaseNode::class, $node);
        $deprecation = $node->getDeprecation('private_entity_manager', 'nowo_workflow.doctrine');
        self::assertSame('nowo-tech/workflow-bundle', $deprecation['package']);
        self::assertSame('2.0', $deprecation['version']);
        self::assertSame('The option has no effect since 2.0 (the private manager is the only mode); remove it.', $deprecation['message']);
    }

    /**
     * Q4: `private_entity_manager: true` stays valid (a deprecated no-op until 3.0); `false` fails the build.
     */
    #[Test]
    public function thePrivateEntityManagerOptOutIsRefused(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => true]]]);
        self::assertTrue($config['doctrine']['private_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_workflow.doctrine.private_entity_manager: false was removed in 2.0');
        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => false]]]);
    }

    /**
     * Q3: an explicit `flush_after_apply: true` stays a supported choice of the application.
     */
    #[Test]
    public function anExplicitFlushAfterApplyIsKept(): void
    {
        foreach ([true, false] as $value) {
            $config = (new Processor())->processConfiguration(new Configuration(), [['runtime' => ['flush_after_apply' => $value]]]);
            self::assertSame($value, $config['runtime']['flush_after_apply']);
        }
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
    public function theConcurrencyModeIsSetPerWorkflow(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['runtime' => ['concurrency' => [
            'default'     => 'optimistic',
            'workflows'   => ['leave' => 'none', 'order' => 'lock'],
            'retry_after' => 3,
        ]]]]);

        self::assertSame(['default' => 'optimistic', 'workflows' => ['leave' => 'none', 'order' => 'lock'], 'retry_after' => 3], $config['runtime']['concurrency']);
    }

    #[Test]
    public function anUnknownConcurrencyModeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['runtime' => ['concurrency' => ['workflows' => ['leave' => 'pessimistic']]]]]);
    }
}
