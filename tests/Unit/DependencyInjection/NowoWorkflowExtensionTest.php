<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

use function array_key_exists;
use function is_array;

final class NowoWorkflowExtensionTest extends TestCase
{
    public function testLoadSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());

        $extension = $container->getExtension('nowo_workflow');
        $extension->load([[
            'enabled'      => true,
            'connection'   => 'custom',
            'table_prefix' => 'wf_',
            'ui'           => [
                'path'           => '/wf',
                'default_locale' => 'fr',
                'locales'        => ['fr', 'en'],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_workflow.enabled'));
        self::assertSame('custom', $container->getParameter('nowo_workflow.connection'));
        self::assertSame('wf_', $container->getParameter('nowo_workflow.table_prefix'));
        self::assertSame('/wf', $container->getParameter('nowo_workflow.ui.path'));
        self::assertSame('fr', $container->getParameter('nowo_workflow.ui.default_locale'));
        self::assertSame(['fr', 'en'], $container->getParameter('nowo_workflow.ui.locales'));
        self::assertSame('@NowoWorkflowBundle/layout.html.twig', $container->getParameter('nowo_workflow.ui.layout_template'));
        self::assertSame('bootstrap5', $container->getParameter('nowo_workflow.ui.css_framework'));
        self::assertSame('bootstrap-icons', $container->getParameter('nowo_workflow.ui.icon_set'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_workflow.security.access_roles'));
        self::assertFalse($container->getParameter('nowo_workflow.security.allow_unauthenticated'));
    }

    public function testPrependTwigFormThemesWhenTwigExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new TwigExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $twigConfigs = $container->getExtensionConfig('twig');
        self::assertNotEmpty($twigConfigs);
        self::assertContains('bootstrap_5_layout.html.twig', $twigConfigs[0]['form_themes'] ?? []);
    }

    public function testPrependBootstrap4FormThemeWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new TwigExtension());
        $container->prependExtensionConfig('nowo_workflow', [
            'ui' => ['css_framework' => 'bootstrap4'],
        ]);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $twigConfigs = $container->getExtensionConfig('twig');
        self::assertContains('bootstrap_4_layout.html.twig', $twigConfigs[0]['form_themes'] ?? []);
    }

    public function testPrependDoctrineMappingsWhenDoctrineExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new DoctrineExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('NowoWorkflowBundle', $doctrineConfigs[0]['orm']['mappings'] ?? []);
    }

    public function testPrependTranslatorPathsWhenFrameworkExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new FrameworkExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($frameworkConfigs);
        self::assertNotEmpty($frameworkConfigs[0]['translator']['paths'] ?? []);
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_workflow', (new NowoWorkflowExtension())->getAlias());
    }

    public function testPrependSeedsFormKitWorkflowProfileWhenHostUnset(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_form_kit');

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap'
                && isset($cfg['profiles']['workflow']['alias'])
                && $cfg['profiles']['workflow']['alias'] === 'workflow'
            ) {
                $found = true;
                self::assertSame('NowoWorkflowBundle', $cfg['profiles']['workflow']['translation_domain']);
                break;
            }
        }
        self::assertTrue($found, 'Expected nowo_form_kit workflow profile and css_framework bootstrap.');
    }

    public function testPrependDoesNotOverrideExplicitFormKitHostConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_form_kit');
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'none',
            'profiles'      => [
                'workflow' => [
                    'alias'              => 'workflow',
                    'translation_domain' => 'HostDomain',
                ],
            ],
        ]);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $bootstrapSeed  = false;
        $workflowReseed = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap') {
                $bootstrapSeed = true;
            }
            if (($cfg['profiles']['workflow']['translation_domain'] ?? null) === 'NowoWorkflowBundle') {
                $workflowReseed = true;
            }
        }
        self::assertFalse($bootstrapSeed);
        self::assertFalse($workflowReseed);
    }

    public function testPrependSeedsUiKitFromUiConfigWhenHostUnset(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_workflow', [
            'ui' => [
                'css_framework' => 'bootstrap',
                'icon_set'      => 'bootstrap-icons',
            ],
        ]);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $found = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'bootstrap5'
                && ($cfg['icon_set'] ?? null) === 'bootstrap-icons'
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testPrependDoesNotOverrideExplicitUiKitHostConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'bootstrap5',
            'icon_set'      => 'none',
        ]);
        $container->prependExtensionConfig('nowo_workflow', [
            'ui' => [
                'css_framework' => 'custom',
                'icon_set'      => 'bootstrap-icons',
            ],
        ]);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $reseeds = 0;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (($cfg['css_framework'] ?? null) === 'custom' || ($cfg['icon_set'] ?? null) === 'bootstrap-icons') {
                ++$reseeds;
            }
        }
        self::assertSame(0, $reseeds);
    }

    public function testPrependIgnoresNonArrayUiKitConfigs(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_ui_kit');

        $ref                      = new ReflectionProperty(ContainerBuilder::class, 'extensionConfigs');
        $configs                  = $ref->getValue($container);
        $configs['nowo_ui_kit'][] = 'invalid';
        $ref->setValue($container, $configs);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $uiSeeded = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (is_array($cfg) && array_key_exists('css_framework', $cfg)) {
                $uiSeeded = true;
            }
        }
        self::assertTrue($uiSeeded);
    }

    private function registerStubExtension(ContainerBuilder $container, string $alias): void
    {
        $container->registerExtension(new class($alias) implements ExtensionInterface {
            public function __construct(private readonly string $extensionAlias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getNamespace(): string
            {
                return '';
            }

            public function getXsdValidationBasePath(): string|false
            {
                return false;
            }

            public function getAlias(): string
            {
                return $this->extensionAlias;
            }
        });
    }
}
