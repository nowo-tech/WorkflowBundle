<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function dirname;
use function in_array;

/**
 * Loads bundle services and exposes configuration as container parameters.
 */
final class NowoWorkflowExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoWorkflowBundle' => [
                            'type'      => 'attribute',
                            'dir'       => dirname(__DIR__) . '/Entity',
                            'prefix'    => 'Nowo\\WorkflowBundle\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        $cssFramework = $this->resolveCssFrameworkFromConfigs($container);
        if ($container->hasExtension('twig') && in_array($cssFramework, ['bootstrap5', 'bootstrap4'], true)) {
            $formTheme = $cssFramework === 'bootstrap4'
                ? 'bootstrap_4_layout.html.twig'
                : 'bootstrap_5_layout.html.twig';
            $container->prependExtensionConfig('twig', [
                'form_themes' => [$formTheme],
            ]);
        }

        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        dirname(__DIR__) . '/Resources/translations',
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(Configuration::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(Configuration::ALIAS . '.environments', $config['environments']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.ui.path', $config['ui']['path']);
        $container->setParameter(Configuration::ALIAS . '.ui.layout_template', $config['ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.ui.css_framework', $config['ui']['css_framework']);
        $container->setParameter(Configuration::ALIAS . '.ui.icon_set', $config['ui']['icon_set']);
        $container->setParameter(Configuration::ALIAS . '.ui.list_page_size', $config['ui']['list_page_size']);
        $container->setParameter(Configuration::ALIAS . '.ui.default_locale', $config['ui']['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.ui.locales', $config['ui']['locales']);
        $container->setParameter(Configuration::ALIAS . '.ui.required_roles', $config['ui']['required_roles']);
        $container->setParameter(Configuration::ALIAS . '.security.access_roles', $config['security']['access_roles']);
        $container->setParameter(Configuration::ALIAS . '.security.access_checker', $config['security']['access_checker']);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    private function resolveCssFrameworkFromConfigs(ContainerBuilder $container): string
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $merged  = $this->processConfiguration(new Configuration(), $configs);

        return (string) $merged['ui']['css_framework'];
    }
}
