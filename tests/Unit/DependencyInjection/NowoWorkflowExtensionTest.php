<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

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

        // 2.0: the 1.x mirrors of the removed keys are gone.
        foreach (['environments', 'ui.path', 'ui.layout_template', 'ui.css_framework', 'ui.css_framework_override', 'ui.icon_set', 'ui.list_page_size', 'ui.default_locale', 'ui.locales', 'ui.required_roles'] as $removed) {
            self::assertFalse($container->hasParameter('nowo_workflow.' . $removed), $removed);
        }
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_workflow.security.access_roles'));
        self::assertFalse($container->getParameter('nowo_workflow.security.allow_unauthenticated'));
    }

    public function testRegistryGetsTheEventDispatcherAndApplicatorTheFlushOption(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->getExtension('nowo_workflow')->load([[]], $container);

        self::assertFalse($container->getParameter('nowo_workflow.runtime.flush_after_apply'), '2.0 default');

        $registry   = $container->getDefinition(DatabaseWorkflowRegistry::class);
        $dispatcher = $registry->getArgument('$eventDispatcher');
        self::assertInstanceOf(Reference::class, $dispatcher);
        self::assertSame('event_dispatcher', (string) $dispatcher);
        self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $dispatcher->getInvalidBehavior());
        self::assertSame('%nowo_workflow.framework_workflow_names%', $registry->getArgument('$frameworkWorkflowNames'));
        self::assertSame([], $container->getParameter('nowo_workflow.framework_workflow_names'));

        $applicator = $container->getDefinition(WorkflowApplicator::class);
        self::assertSame('%nowo_workflow.runtime.flush_after_apply%', $applicator->getArgument('$flushAfterApply'));
    }

    public function testFlushAfterApplyCanBeEnabledExplicitly(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->getExtension('nowo_workflow')->load([['runtime' => ['flush_after_apply' => true]]], $container);

        self::assertTrue($container->getParameter('nowo_workflow.runtime.flush_after_apply'));
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

    public function testPrependNeverSeedsUiKit(): void
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
            $this->registerStubExtension($container, 'nowo_form_kit');
            $this->registerStubExtension($container, 'nowo_ui_kit');
            $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => $cssFramework]);

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
        // REQ-ASSETS-004: named asset package matching the assets:install output.
        self::assertSame(
            ['base_path' => '/bundles/nowoworkflow'],
            $frameworkConfigs[0]['assets']['packages']['nowo_workflow'] ?? null,
        );
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

    public function testPrependIgnoresNonArrayUiKitConfigs(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $this->registerStubExtension($container, 'nowo_ui_kit');

        $ref                      = new ReflectionProperty(ContainerBuilder::class, 'extensionConfigs');
        $configs                  = $ref->getValue($container);
        $configs['nowo_ui_kit'][] = 'invalid';
        $ref->setValue($container, $configs);
        $this->registerStubExtension($container, 'nowo_form_kit');

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        // The invalid UiKit config is skipped: FormKit is seeded from UiKit's default stack (bootstrap5).
        $seed = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertSame('bootstrap', $seed['css_framework']);
        self::assertSame(['invalid'], $container->getExtensionConfig('nowo_ui_kit'), 'UiKit is never seeded');
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
