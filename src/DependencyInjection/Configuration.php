<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for nowo_workflow.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_workflow';

    public const CSS_FRAMEWORKS = [
        'bootstrap',
        'bootstrap4',
        'bootstrap5',
        'tailwind',
        'foundation',
        'custom',
        'tabler',
        'none',
    ];

    public const ICON_SETS = [
        'bootstrap-icons',
        'tabler-icons',
        'ux_icon',
        'svg_inline',
        'none',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->arrayNode('environments')
                    ->prototype('scalar')->end()
                    ->defaultValue(['dev', 'test', 'prod'])
                ->end()
                ->scalarNode('connection')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('workflow_')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('/workflow')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Twig layout extended by Workflow UI pages (global nowo_workflow_layout_template). Host apps should set this to the project layout.')
                            ->defaultValue('@NowoWorkflowBundle/layout.html.twig')
                            ->cannotBeEmpty()
                        ->end()
                        ->enumNode('css_framework')
                            ->info('CSS stack for Workflow UI markup. bootstrap is an alias of bootstrap5.')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultValue('bootstrap5')
                        ->end()
                        ->enumNode('icon_set')
                            ->info('Icon rendering for row actions and toolbars.')
                            ->values(self::ICON_SETS)
                            ->defaultValue('bootstrap-icons')
                        ->end()
                        ->integerNode('list_page_size')
                            ->info('Definitions shown per page on dashboard/index lists (REQ-PERF-001). Use 0 to load all (not recommended in production).')
                            ->defaultValue(20)
                            ->min(0)
                            ->max(500)
                        ->end()
                        ->scalarNode('default_locale')
                            ->defaultValue('en')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('locales')
                            ->scalarPrototype()->end()
                            ->defaultValue(['en', 'es', 'fr', 'it'])
                        ->end()
                        ->arrayNode('required_roles')
                            ->info('BC alias of security.access_roles. Prefer security.access_roles.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->example(['ROLE_ADMIN'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('User must have at least one role. Empty list = no bundle-level role check.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->example(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing WorkflowUiAccessCheckerInterface. null = RoleBasedWorkflowUiAccessChecker.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV/DEMO ONLY. When true, UI may load without SecurityBundle. Never true in production.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $config): array {
                    if ($config['ui']['css_framework'] === 'bootstrap') {
                        $config['ui']['css_framework'] = 'bootstrap5';
                    }
                    if ($config['ui']['css_framework'] === 'tabler') {
                        $config['ui']['css_framework'] = 'bootstrap5';
                    }

                    // Prefer explicit security.access_roles when both differ from defaults;
                    // keep ui.required_roles as BC mirror when security uses default and ui was customized.
                    $uiRoles       = $config['ui']['required_roles'];
                    $securityRoles = $config['security']['access_roles'];
                    $defaultRoles  = ['ROLE_ADMIN'];

                    if ($securityRoles === $defaultRoles && $uiRoles !== $defaultRoles) {
                        $config['security']['access_roles'] = $uiRoles;
                    } else {
                        $config['ui']['required_roles'] = $securityRoles;
                    }

                    return $config;
                })
            ->end();

        return $treeBuilder;
    }
}
