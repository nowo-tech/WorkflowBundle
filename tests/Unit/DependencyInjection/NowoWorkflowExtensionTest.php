<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
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
            'connection'   => 'custom',
            'table_prefix' => 'wf_',
            'web_ui'       => [
                'path_prefix'    => '/wf',
                'default_locale' => 'fr',
                'locales'        => ['fr', 'en'],
                'templates'      => ['show' => 'admin/workflow_show.html.twig'],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_workflow.enabled'));
        self::assertTrue($container->getParameter('nowo_workflow.web_ui.enabled'));
        self::assertSame('custom', $container->getParameter('nowo_workflow.connection'));
        self::assertSame('wf_', $container->getParameter('nowo_workflow.table_prefix'));
        self::assertSame('/wf', $container->getParameter('nowo_workflow.web_ui.path_prefix'));
        self::assertNull($container->getParameter('nowo_workflow.web_ui.layout_template'));
        self::assertSame(20, $container->getParameter('nowo_workflow.web_ui.list_page_size'));
        self::assertSame('fr', $container->getParameter('nowo_workflow.web_ui.default_locale'));
        self::assertSame(['fr', 'en'], $container->getParameter('nowo_workflow.web_ui.locales'));
        $templates = $container->getParameter('nowo_workflow.web_ui.templates');
        self::assertIsArray($templates);
        self::assertSame('admin/workflow_show.html.twig', $templates['show']);
        self::assertSame(Configuration::DEFAULT_TEMPLATES['index'], $templates['index']);

        // Deprecated 1.x mirrors.
        self::assertSame('/wf', $container->getParameter('nowo_workflow.ui.path'));
        self::assertSame('fr', $container->getParameter('nowo_workflow.ui.default_locale'));
        self::assertSame(['fr', 'en'], $container->getParameter('nowo_workflow.ui.locales'));
        self::assertSame(20, $container->getParameter('nowo_workflow.ui.list_page_size'));
        self::assertSame(NowoWorkflowExtension::UI_KIT_ADMIN_LAYOUT, $container->getParameter('nowo_workflow.ui.layout_template'));
        self::assertSame('bootstrap5', $container->getParameter('nowo_workflow.ui.css_framework'));
        self::assertNull($container->getParameter('nowo_workflow.ui.css_framework_override'));
        self::assertSame('bootstrap-icons', $container->getParameter('nowo_workflow.ui.icon_set'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_workflow.ui.required_roles'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_workflow.security.access_roles'));
        self::assertFalse($container->getParameter('nowo_workflow.security.allow_unauthenticated'));
    }

    public function testLoadMapsDeprecatedUiKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());

        $container->getExtension('nowo_workflow')->load([[
            'enabled' => false,
            'ui'      => [
                'path'            => '/legacy',
                'layout_template' => 'base.html.twig',
                'css_framework'   => 'tailwind',
                'icon_set'        => 'none',
            ],
        ]], $container);

        self::assertFalse($container->getParameter('nowo_workflow.enabled'));
        self::assertFalse($container->getParameter('nowo_workflow.web_ui.enabled'));
        self::assertSame('/legacy', $container->getParameter('nowo_workflow.web_ui.path_prefix'));
        self::assertSame(Configuration::LEGACY_LAYOUT_BRIDGE, $container->getParameter('nowo_workflow.web_ui.layout_template'));
        self::assertSame('base.html.twig', $container->getParameter('nowo_workflow.ui.layout_template'));
        self::assertSame('tailwind', $container->getParameter('nowo_workflow.ui.css_framework'));
        self::assertSame('tailwind', $container->getParameter('nowo_workflow.ui.css_framework_override'));
        self::assertSame('none', $container->getParameter('nowo_workflow.ui.icon_set'));
    }

    public function testFormKitProfileFollowsTheCssStack(): void
    {
        foreach (['bootstrap', 'bootstrap5', 'bootstrap4', 'tabler'] as $framework) {
            $profile = NowoWorkflowExtension::formKitProfile($framework);
            self::assertSame('nowo-ui-input nowo-ui-input--full form-control', $profile['defaults']['attr']['class'], $framework);
            self::assertArrayHasKey('field_types', $profile);
            self::assertSame('bootstrap', NowoWorkflowExtension::formKitCssFramework($framework));
        }

        $profile = NowoWorkflowExtension::formKitProfile('tailwind');
        self::assertSame(['attr' => ['class' => 'nowo-ui-input nowo-ui-input--full']], $profile['defaults']);
        self::assertArrayNotHasKey('field_types', $profile);
        self::assertSame('NowoWorkflowBundle', $profile['translation_domain']);

        self::assertSame('tailwind', NowoWorkflowExtension::formKitCssFramework('tailwind'));
        self::assertSame('foundation', NowoWorkflowExtension::formKitCssFramework('foundation'));
        self::assertSame('none', NowoWorkflowExtension::formKitCssFramework('custom'));
        self::assertSame('none', NowoWorkflowExtension::formKitCssFramework('none'));
    }

    public function testPrependSeedsFormKitFromTheHostUiKitStack(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_form_kit');
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind']);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $seed = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertSame('tailwind', $seed['css_framework']);
        self::assertSame(['attr' => ['class' => 'nowo-ui-input nowo-ui-input--full']], $seed['profiles']['workflow']['defaults']);
    }

    public function testPrependSeedsFormKitFromTheDeprecatedUiCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_form_kit');
        $this->registerStubExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', ['icon_set' => 'none']);
        $container->prependExtensionConfig('nowo_workflow', ['ui' => ['css_framework' => 'foundation']]);

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $seed = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertSame('foundation', $seed['css_framework']);
    }

    public function testPrependDoesNotSeedUiKitWithoutDeprecatedKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_ui_kit');

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependDoesNotRegisterGlobalTwigFormThemes(): void
    {
        foreach (['bootstrap5', 'bootstrap4'] as $cssFramework) {
            $container = new ContainerBuilder();
            $container->registerExtension(new NowoWorkflowExtension());
            $container->registerExtension(new TwigExtension());
            $container->prependExtensionConfig('nowo_workflow', [
                'ui' => ['css_framework' => $cssFramework],
            ]);

            $extension = $container->getExtension('nowo_workflow');
            self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
            $extension->prepend($container);

            foreach ($container->getExtensionConfig('twig') as $twigConfig) {
                self::assertArrayNotHasKey('form_themes', $twigConfig, 'Host forms must not be restyled globally.');
            }
        }
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
        $container->prependExtensionConfig('nowo_workflow', ['ui' => ['css_framework' => 'bootstrap5']]);

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
