<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use Nowo\UiKitBundle\Theme\ThemeSlots;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * Loads bundle services and exposes configuration as container parameters.
 */
final class NowoWorkflowExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Value of the deprecated nowo_workflow_layout_template global when no 1.x layout is configured.
     */
    public const UI_KIT_ADMIN_LAYOUT = '@NowoUiKit/layout/admin.html.twig';

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoWorkflowBundle' => [
                            'type'      => 'attribute',
                            'dir'       => __DIR__ . '/../Entity',
                            'prefix'    => 'Nowo\\WorkflowBundle\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        // Never prepend twig.form_themes globally (it would restyle every host form): the Workflow forms render with
        // the host form theme and the FormKit "workflow" profile (REQ-UI-003).

        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        __DIR__ . '/../Resources/translations',
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the workflow profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('workflow', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed      = [];
        $framework = $this->resolvedCssFramework($container);

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = self::formKitCssFramework($framework);
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = ['workflow' => self::formKitProfile($framework)];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * FormKit profile of the Workflow forms: Bootstrap control classes on Bootstrap stacks, semantic nowo-ui-* classes
     * only on any other stack (the host theme styles them). Text controls use the UiKit 2.1 `input_full` slot
     * (`nowo-ui-input nowo-ui-input--full`): the form grid cells and the editor dialogs size them.
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(string $cssFramework): array
    {
        $input = ThemeSlots::semantic('input_full');

        if (!self::isBootstrap($cssFramework)) {
            return [
                'alias'              => 'workflow',
                'translation_domain' => 'NowoWorkflowBundle',
                'defaults'           => ['attr' => ['class' => $input]],
            ];
        }

        return [
            'alias'              => 'workflow',
            'translation_domain' => 'NowoWorkflowBundle',
            'defaults'           => [
                'attr'     => ['class' => $input . ' form-control'],
                'row_attr' => ['class' => 'mb-2'],
            ],
            'field_types' => [
                'checkbox' => [
                    'attr'     => ['class' => 'form-check-input'],
                    'row_attr' => ['class' => 'form-check mb-2'],
                ],
                'choice' => [
                    'attr' => ['class' => 'form-select'],
                ],
                'entity' => [
                    'attr' => ['class' => 'form-select'],
                ],
                'file' => [
                    'attr' => ['class' => $input . ' form-control'],
                ],
                'textarea' => [
                    'attr' => ['class' => $input . ' form-control'],
                ],
            ],
        ];
    }

    /**
     * FormKit css_framework (bootstrap, tailwind, foundation, none) of a UiKit css_framework.
     */
    public static function formKitCssFramework(string $cssFramework): string
    {
        return match (true) {
            self::isBootstrap($cssFramework)                          => 'bootstrap',
            in_array($cssFramework, ['tailwind', 'foundation'], true) => $cssFramework,
            default                                                   => 'none',
        };
    }

    private static function isBootstrap(string $cssFramework): bool
    {
        return in_array($cssFramework, ['bootstrap', 'bootstrap5', 'bootstrap4', 'tabler'], true);
    }

    /**
     * CSS stack the Workflow pages render with: the host's nowo_ui_kit.css_framework, else the deprecated
     * ui.css_framework, else UiKit's default (bootstrap5).
     */
    private function resolvedCssFramework(ContainerBuilder $container): string
    {
        $framework = null;
        if ($container->hasExtension('nowo_ui_kit')) {
            foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
                if (is_array($cfg) && is_string($cfg['css_framework'] ?? null)) {
                    $framework = $cfg['css_framework'];
                }
            }
        }

        if ($framework === null) {
            $legacy    = $this->processedConfig($container)['ui']['css_framework'] ?? null;
            $framework = is_string($legacy) ? $legacy : 'bootstrap5';
        }

        return $framework;
    }

    /**
     * Seeds nowo_ui_kit.css_framework / icon_set from the deprecated ui.css_framework / ui.icon_set when the host
     * sets them and does not configure UiKit itself (REQ-UI-003: the CSS stack is inherited from nowo_ui_kit).
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $ui       = $this->processedConfig($container)['ui'];
        $defaults = [];

        if (!$hostHasCssFramework && is_string($ui['css_framework'] ?? null)) {
            $defaults['css_framework'] = $ui['css_framework'];
        }
        if (!$hostHasIconSet && is_string($ui['icon_set'] ?? null)) {
            $defaults['icon_set'] = $ui['icon_set'];
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processedConfig(ContainerBuilder $container): array
    {
        /** @var array<string, mixed> $config */
        $config = (new Processor())->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));

        return $config;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);
        $webUi  = $config['web_ui'];
        $ui     = $config['ui'];

        $container->setParameter(Configuration::ALIAS . '.enabled', $webUi['enabled']);
        $container->setParameter(Configuration::ALIAS . '.environments', $config['environments']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);

        $container->setParameter(Configuration::ALIAS . '.web_ui.enabled', $webUi['enabled']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.path_prefix', $webUi['path_prefix']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.templates', $webUi['templates']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.list_page_size', $webUi['list_page_size']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.default_locale', $webUi['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.locales', $webUi['locales']);

        // Deprecated 1.x parameters (mirrors of the canonical values), removed in 2.0.
        $legacyLayout = Configuration::legacyLayout($config);
        $cssFramework = is_string($ui['css_framework'] ?? null) ? $ui['css_framework'] : null;
        $iconSet      = is_string($ui['icon_set'] ?? null) ? $ui['icon_set'] : null;
        $container->setParameter(Configuration::ALIAS . '.ui.path', $webUi['path_prefix']);
        $container->setParameter(Configuration::ALIAS . '.ui.layout_template', $legacyLayout ?? self::UI_KIT_ADMIN_LAYOUT);
        $container->setParameter(Configuration::ALIAS . '.ui.css_framework', $cssFramework ?? 'bootstrap5');
        $container->setParameter(Configuration::ALIAS . '.ui.css_framework_override', $cssFramework);
        $container->setParameter(Configuration::ALIAS . '.ui.icon_set', $iconSet ?? 'bootstrap-icons');
        $container->setParameter(Configuration::ALIAS . '.ui.list_page_size', $webUi['list_page_size']);
        $container->setParameter(Configuration::ALIAS . '.ui.default_locale', $webUi['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.ui.locales', $webUi['locales']);
        $container->setParameter(Configuration::ALIAS . '.ui.required_roles', $config['security']['access_roles']);

        $container->setParameter(Configuration::ALIAS . '.security.access_roles', $config['security']['access_roles']);
        $container->setParameter(Configuration::ALIAS . '.security.access_checker', $config['security']['access_checker']);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
