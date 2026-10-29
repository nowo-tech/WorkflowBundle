<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use Nowo\WorkflowBundle\Enum\CssFramework;
use Nowo\WorkflowBundle\Enum\IconSet;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * Configuration tree for nowo_workflow.
 *
 * Canonical UI keys follow the REQ-UI-003 vocabulary ({@code web_ui.*}, {@code security.*}). The 1.x {@code ui.*}
 * keys and the root {@code enabled} key are deprecated aliases (mapped before normalization, removed in 2.0).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_workflow';

    public const PACKAGE = 'nowo-tech/workflow-bundle';

    public const DEPRECATED_SINCE = '1.9';

    /**
     * 1.x default layout (Bootstrap from a CDN), removed in 1.9: a host that still names it gets the UiKit layout.
     */
    public const REMOVED_DEFAULT_LAYOUT = '@NowoWorkflowBundle/layout.html.twig';

    /**
     * Deprecated bridge used when a host still sets {@code ui.layout_template}: it extends that 1.x layout and maps
     * the UiKit canonical blocks into its stylesheets / body / javascripts blocks.
     */
    public const LEGACY_LAYOUT_BRIDGE = '@NowoWorkflowBundle/base.html.twig';

    /**
     * Per-page templates ({@code web_ui.templates.<page>}).
     *
     * @var array<string, string>
     */
    public const DEFAULT_TEMPLATES = [
        'dashboard' => '@NowoWorkflowBundle/dashboard/index.html.twig',
        'index'     => '@NowoWorkflowBundle/workflow_definition/index.html.twig',
        'show'      => '@NowoWorkflowBundle/workflow_definition/show.html.twig',
        'new'       => '@NowoWorkflowBundle/workflow_definition/form.html.twig',
        'edit'      => '@NowoWorkflowBundle/workflow_definition/edit.html.twig',
    ];

    /**
     * Deprecated {@code ui.*} key => canonical {@code web_ui.*} key.
     *
     * @var array<string, string>
     */
    private const UI_ALIASES = [
        'path'           => 'path_prefix',
        'list_page_size' => 'list_page_size',
        'default_locale' => 'default_locale',
        'locales'        => 'locales',
    ];

    /**
     * Builds the nowo_workflow configuration tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->beforeNormalization()
                ->ifArray()
                ->then(static fn (array $v): array => self::mapDeprecatedKeys($v))
            ->end()
            ->children()
                ->booleanNode('enabled')
                    ->info('Deprecated alias of web_ui.enabled.')
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.enabled" instead.')
                ->end()
                ->arrayNode('environments')
                    ->info('Deprecated and ignored: the bundle is enabled in every environment it is registered for (config/bundles.php).')
                    ->prototype('scalar')->end()
                    ->defaultValue(['dev', 'test', 'prod'])
                    ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated and has no effect; register the bundle only for the wanted environments in config/bundles.php instead.')
                ->end()
                ->scalarNode('connection')
                    ->info('Entity manager name used by nowo:workflow:sync-schema. The bundle mapping is registered on the default entity manager; for another manager, map NowoWorkflowBundle on it yourself.')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('workflow_')
                    ->cannotBeEmpty()
                ->end()
                ->append($this->webUiNode())
                ->append($this->deprecatedUiNode())
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('User must have at least one role. Empty list is rejected unless allow_unauthenticated or access_checker is set.')
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
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (!empty($v['allow_unauthenticated'])) {
                                return false;
                            }
                            $checker = $v['access_checker'] ?? null;
                            if (is_string($checker) && $checker !== '') {
                                return false;
                            }
                            $roles = array_values(array_filter(
                                is_array($v['access_roles'] ?? null) ? $v['access_roles'] : [],
                                static fn (mixed $r): bool => is_string($r) && $r !== '',
                            ));

                            return $roles === [];
                        })
                        ->thenInvalid('nowo_workflow.security.access_roles must contain at least one non-empty role when allow_unauthenticated is false and no access_checker is set.')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $config): array {
                    $ui = $config['ui'];
                    if (is_string($ui['css_framework'] ?? null)) {
                        $config['ui']['css_framework'] = CssFramework::from($ui['css_framework'])->normalized()->value;
                    }

                    // 1.x mirror: ui.required_roles is the deprecated alias of security.access_roles.
                    $config['ui']['required_roles'] = $config['security']['access_roles'];

                    return $config;
                })
            ->end();

        return $treeBuilder;
    }

    private function webUiNode(): ArrayNodeDefinition
    {
        $node      = (new TreeBuilder('web_ui'))->getRootNode();
        $templates = (new TreeBuilder('templates'))->getRootNode();
        $templates->info('Per-page template overrides (REQ-UI-003): a host template that extends @!NowoWorkflowBundle/… and overrides a data block.')
            ->addDefaultsIfNotSet();
        $templateChildren = $templates->children();
        foreach (self::DEFAULT_TEMPLATES as $page => $template) {
            $templateChildren->scalarNode($page)->defaultValue($template)->cannotBeEmpty()->end();
        }

        $node
            ->info('Workflow admin UI (REQ-UI-003 vocabulary).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('When false every Workflow UI route answers 404 (the registry keeps working).')
                    ->defaultTrue()
                ->end()
                ->scalarNode('path_prefix')
                    ->info('URL prefix of the Workflow UI routes.')
                    ->defaultValue('/workflow')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('layout_template')
                    ->info('Layout extended by the Workflow pages. null = UiKit layout (nowo_ui_kit.layouts.admin). The template must render the UiKit canonical blocks.')
                    ->defaultNull()
                ->end()
                ->append($templates)
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
            ->end();

        return $node;
    }

    private function deprecatedUiNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('ui'))->getRootNode();
        $node
            ->info('Deprecated since 1.9: use web_ui.*, security.access_roles and nowo_ui_kit.css_framework / icon_set.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('path')
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.path_prefix" instead.')
                ->end()
                ->scalarNode('layout_template')
                    ->info('1.x layout (global nowo_workflow_layout_template). Pages render through the deprecated @NowoWorkflowBundle/base.html.twig bridge while it is set.')
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.layout_template" (a layout rendering the UiKit canonical blocks; null = nowo_ui_kit.layouts.admin) instead.')
                ->end()
                ->enumNode('css_framework')
                    ->info('Seeds nowo_ui_kit.css_framework when the host does not set it. bootstrap / tabler are aliases of bootstrap5.')
                    ->values([...CssFramework::values(), null])
                    ->defaultNull()
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.css_framework" instead.')
                ->end()
                ->enumNode('icon_set')
                    ->info('Seeds nowo_ui_kit.icon_set when the host does not set it.')
                    ->values([...IconSet::values(), null])
                    ->defaultNull()
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.icon_set" instead.')
                ->end()
                ->integerNode('list_page_size')
                    ->min(0)
                    ->max(500)
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.list_page_size" instead.')
                ->end()
                ->scalarNode('default_locale')
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.default_locale" instead.')
                ->end()
                ->arrayNode('locales')
                    ->scalarPrototype()->end()
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.web_ui.locales" instead.')
                ->end()
                ->arrayNode('required_roles')
                    ->info('BC alias of security.access_roles.')
                    ->scalarPrototype()->end()
                    ->setDeprecated(self::PACKAGE, self::DEPRECATED_SINCE, 'The "%path%.%node%" option is deprecated, use "nowo_workflow.security.access_roles" instead.')
                ->end()
            ->end();

        return $node;
    }

    /**
     * Copies the deprecated 1.x keys to their canonical names (an explicit canonical key wins). The old keys stay in
     * place so the config component reports their deprecation.
     *
     * @param array<mixed> $v
     *
     * @return array<mixed>
     */
    public static function mapDeprecatedKeys(array $v): array
    {
        $webUi = is_array($v['web_ui'] ?? null) ? $v['web_ui'] : [];

        if (array_key_exists('enabled', $v) && !array_key_exists('enabled', $webUi)) {
            $webUi['enabled'] = $v['enabled'];
        }

        $ui = is_array($v['ui'] ?? null) ? $v['ui'] : [];
        foreach (self::UI_ALIASES as $old => $new) {
            if (array_key_exists($old, $ui) && !array_key_exists($new, $webUi)) {
                $webUi[$new] = $ui[$old];
            }
        }

        if (array_key_exists('layout_template', $ui) && !array_key_exists('layout_template', $webUi)) {
            $legacy                   = $ui['layout_template'];
            $webUi['layout_template'] = in_array($legacy, [null, '', self::REMOVED_DEFAULT_LAYOUT], true)
                ? null
                : self::LEGACY_LAYOUT_BRIDGE;
        }

        if (array_key_exists('required_roles', $ui) && !isset($v['security']['access_roles'])) {
            $security                 = is_array($v['security'] ?? null) ? $v['security'] : [];
            $security['access_roles'] = $ui['required_roles'];
            $v['security']            = $security;
        }

        if ($webUi !== [] || array_key_exists('web_ui', $v)) {
            $v['web_ui'] = $webUi;
        }

        return $v;
    }

    /**
     * The 1.x layout a host still configures through the deprecated {@code ui.layout_template} (null when unset or
     * when it names the removed default layout).
     *
     * @param array<mixed> $config processed configuration
     */
    public static function legacyLayout(array $config): ?string
    {
        $ui     = is_array($config['ui'] ?? null) ? $config['ui'] : [];
        $legacy = $ui['layout_template'] ?? null;

        return is_string($legacy) && $legacy !== '' && $legacy !== self::REMOVED_DEFAULT_LAYOUT ? $legacy : null;
    }
}
