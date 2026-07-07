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
                        ->scalarNode('default_locale')
                            ->defaultValue('en')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('locales')
                            ->scalarPrototype()->end()
                            ->defaultValue(['en', 'es', 'fr', 'it'])
                        ->end()
                        ->arrayNode('required_roles')
                            ->info('When set, Flex/recipe should alias RoleBasedWorkflowUiAccessChecker with these roles.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->example(['ROLE_ADMIN'])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
