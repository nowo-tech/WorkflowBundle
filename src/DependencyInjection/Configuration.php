<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Enum\CssFramework;
use Nowo\WorkflowBundle\Enum\IconSet;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

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
                    ->info('Entity manager name used by nowo:workflow:sync-schema (DDL only). The bundle mapping is registered on the default entity manager; for another manager, map NowoWorkflowBundle on it yourself and set doctrine.entity_manager too.')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('workflow_')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('runtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('flush_after_apply')
                            ->info('When true, WorkflowApplicator::apply*() flushes the application\'s default entity manager after each transition (every pending change of its unit of work, REQ-ARCH-001 rule 6 exception kept for BC). False = apply*() only changes the marking; persist the subject yourself (your repository / transaction boundary). Since 1.14 new installs get false from the Flex recipe; when the key is not set the 1.13 default (true) stays and a deprecation is triggered: the default becomes false in 2.0.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('concurrency')
                            ->info('Since 1.20 (REQ-RUNTIME-003, docs/CONCURRENCY.md): how a transition apply on a persisted Doctrine entity is protected against a concurrent apply. lock (default) = expected-marking compare-and-set under SELECT … FOR UPDATE; optimistic = the subject\'s #[Version] field decides at the flush; none = no control (1.19 behaviour). A lost race throws WorkflowConflictException (409 + Retry-After, CONFLICT_001).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->enumNode('default')
                                    ->info('Mode of every workflow not listed under workflows.')
                                    ->values(['lock', 'optimistic', 'none'])
                                    ->defaultValue('lock')
                                ->end()
                                ->arrayNode('workflows')
                                    ->info('Mode per workflow slug, e.g. {leave_request: optimistic}.')
                                    ->useAttributeAsKey('slug')
                                    ->enumPrototype()
                                        ->values(['lock', 'optimistic', 'none'])
                                    ->end()
                                ->end()
                                ->integerNode('retry_after')
                                    ->info('Retry-After (seconds) of a conflict.')
                                    ->min(0)
                                    ->defaultValue(1)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Since 1.14: persistence of the workflow definitions (REQ-ARCH-001 rules 2 and 6).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')
                            ->info('Source manager of the bundle\'s private entity manager: its connection, mapping configuration and event manager are shared, it is never flushed, cleared or reset by the bundle. null (default) = the manager that maps the bundle entities (the default manager of a standard application). A dedicated manager must exist under doctrine.orm.entity_managers (the build fails otherwise) and map the bundle entities (set doctrine.register_mapping: false and map them there yourself).')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || $v === ''))
                                ->thenInvalid('nowo_workflow.doctrine.entity_manager must be an entity manager name or null; got %s.')
                            ->end()
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->info('true (default): the admin UI, the demo seeder and the repository read and write the definitions through the bundle\'s private entity manager, so a definition save never flushes or resets an application manager. false restores the 1.13 behaviour (the application\'s default manager): deprecated, removed in 2.0.')
                            ->defaultTrue()
                            ->setDeprecated(self::PACKAGE, '1.14', 'The "%node%" option at path "%path%" is deprecated: the private entity manager becomes the only mode in 2.0. Remove the option.')
                        ->end()
                        ->booleanNode('register_mapping')
                            ->info('true (default): the bundle maps its entities on the default entity manager. false = the application maps them itself (e.g. only on a dedicated manager of a DDD host, from src/Resources/config/doctrine).')
                            ->defaultTrue()
                        ->end()
                        ->enumNode('mapping_type')
                            ->info('The mapping the bundle registers (register_mapping: true): attribute (default) or xml (src/Resources/config/doctrine/*.orm.xml, same tables and columns).')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                        ->end()
                    ->end()
                ->end()
                ->append($this->tenancyNode())
                ->append($this->guardsNode())
                ->append($this->searchKitNode())
                ->arrayNode('audit')
                    ->info('Since 1.15: WorkflowTransitionAppliedEvent after the commit of every applied transition (ids only; forward it to an audit log). Requires doctrine/dbal 4.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('null (default) = on with tenancy (tenancy.tenant_resolver set), off without.')
                            ->defaultNull()
                        ->end()
                    ->end()
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
                ->ifTrue(static fn (array $config): bool => is_string($config['tenancy']['tenant_resolver'] ?? null)
                    && ($config['web_ui']['enabled'] ?? true) === true
                    && (!is_string($config['tenancy']['ui']['tenant_resolver'] ?? null) || !is_string($config['tenancy']['ui']['action_checker'] ?? null)))
                ->thenInvalid('With tenancy (nowo_workflow.tenancy.tenant_resolver) and the Workflow UI enabled, set nowo_workflow.tenancy.ui.tenant_resolver and nowo_workflow.tenancy.ui.action_checker (or disable web_ui): the UI must know the tenant of each request and who may view, edit and publish (deny by default).')
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

    /**
     * Since 1.15: tenant-scoped definitions (docs/MULTITENANCY.md). Every option is a service id of a host port.
     */
    private function tenancyNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('tenancy'))->getRootNode();
        $node
            ->info('Since 1.15: tenant-scoped workflow definitions, global templates, versions pinned per subject. Off (1.14 behaviour) unless tenant_resolver is set.')
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->serviceIdNode('tenant_resolver', 'Service id implementing WorkflowTenantResolverInterface (the tenant of a subject). Setting it turns tenancy on.'))
                ->append($this->serviceIdNode('subject_reference_resolver', 'Service id implementing WorkflowSubjectReferenceResolverInterface (a stable id of a subject): pins the version a subject started with; also used by permission checks and the audit event.'))
                ->append($this->serviceIdNode('actor_provider', 'Service id implementing WorkflowActorProviderInterface. null = the Symfony security token\'s user identifier (when SecurityBundle is installed).'))
                ->arrayNode('subject_classes')
                    ->info('Subject classes a tenant scope may create workflows for, with their marking property (e.g. App\\Leave\\LeaveRequest: status). Tenants cannot change the binding of a definition; global templates are not restricted. Empty = tenants can only clone templates.')
                    ->useAttributeAsKey('class')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('label_domains')
                    ->info('Since 1.16: translation domains the editor accepts for the label key of a place or a transition (letters, digits, "_", "." or "-").')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['messages', 'workflows'])
                ->end()
                ->booleanNode('lock_clone_structure')
                    ->info('Since 1.16: tenant clones of a global template keep its places and transitions (and initial place); tenants only edit guards and conditions. Enforced by the editor and when a draft is published. Applies to every tenant definition whose slug has a global template.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->serviceIdNode('tenant_resolver', 'Service id implementing WorkflowUiTenantResolverInterface (the tenant scope of a UI request, e.g. from the {uuid} of a host route prefix). Required with tenancy and the UI.'))
                        ->append($this->serviceIdNode('action_checker', 'Service id implementing WorkflowUiActionCheckerInterface (view / edit / publish per scope). Required with tenancy and the UI; optional without tenancy (asked on top of the access policy).'))
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Since 1.15: per-transition guards configured in the editor.
     */
    private function guardsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('guards'))->getRootNode();
        $node
            ->info('Since 1.15: guards an editor sets per transition (permission in the subject\'s context, conditions on whitelisted variables, automatic transitions). Fields are hidden while their port is not configured; a stored guard without its port blocks the transition.')
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->serviceIdNode('permission_checker', 'Service id implementing WorkflowTransitionPermissionCheckerInterface.'))
                ->append($this->serviceIdNode('variables_provider', 'Service id implementing WorkflowSubjectVariablesProviderInterface (the whitelist of scalar variables conditions may read).'))
                ->integerNode('max_automatic_transitions')
                    ->info('Most automatic transitions applied one after the other for one subject (loop guard).')
                    ->defaultValue(10)
                    ->min(0)
                    ->max(100)
                ->end()
            ->end();

        return $node;
    }

    /**
     * Since 1.21: the SearchKit groups of the workflow requests, one per subject (docs/USAGE.md, "Header search").
     */
    private function searchKitNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('search_kit'))->getRootNode();
        $node
            ->info('Since 1.21: one SearchKit group per subject (SearchKit >= 1.10, PHP >= 8.3): the host\'s workflow requests of one type, matched on a viewer-neutral search text and the status, only those the viewer may see (WorkflowRequestAccessCheckEvent, deny by default). Registered only when enabled and SearchKit is installed; reorder or switch a group off with nowo_search_kit.groups.<group>.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('true = register a group per subject.')->end()
                ->arrayNode('subjects')
                    ->info('Request types by subject key (a lower-case letter, then a-z, 0-9, _). The key is carried by the access event and the title resolver.')
                    ->useAttributeAsKey('subject')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('group')->defaultNull()->info('SearchKit group key (default workflow_<subject>; never nowo_search_*).')->end()
                            ->integerNode('priority')->defaultValue(0)->info('Tag priority of the group among the SearchKit providers.')->end()
                            ->scalarNode('subject_class')->defaultNull()->info('The request entity (Doctrine ORM), e.g. App\\Leave\\LeaveRequest. Required.')->end()
                            ->scalarNode('gate')->defaultNull()->info('Service id implementing WorkflowRequestSearchGateInterface (the viewer\'s tenant and the feature switch). Required.')->end()
                            ->scalarNode('title_resolver')->defaultNull()->info('Service id implementing WorkflowRequestTitleResolverInterface (the shown titles, one batch of visible ids). Or title_field.')->end()
                            ->scalarNode('narrower')->defaultNull()->info('Service id implementing WorkflowRequestSearchNarrowerInterface (extra SQL conditions for the viewer, e.g. requester or approver).')->end()
                            ->integerNode('narrower_max_values')->defaultValue(WorkflowRequestSearchCriteria::DEFAULT_MAX_VALUES)->min(1)->max(WorkflowRequestSearchOptions::MAX_NARROWER_VALUES)->info('The most values the narrower may give one field (split into IN lists of 1000; at most 60000, and 60000 for all fields together). More fails closed: no group, no SQL and a logged warning.')->end()
                            ->scalarNode('id_field')->defaultValue('id')->cannotBeEmpty()->info('Mapped field of the id used in the URL, the access event, the title resolver and recents.')->end()
                            ->scalarNode('search_text_field')->defaultNull()->info('Mapped field the host maintains with viewer-neutral search text (e.g. person, type, dates). Never health or legal data (attachments, comments, justification, on-behalf reason). Or title_field.')->end()
                            ->scalarNode('title_field')->defaultNull()->info('Mapped field shown as the title (without a title_resolver) and searched (without a search_text_field).')->end()
                            ->scalarNode('status_field')->defaultValue('status')->cannotBeEmpty()->info('Mapped field of the status: a workflow place name, or a string-backed enum column. Its stored value is searched; its label is shown.')->end()
                            ->scalarNode('tenant_field')->defaultNull()->info('Mapped field compared with the gate\'s tenant in SQL. Required unless single_tenant.')->end()
                            ->booleanNode('single_tenant')->defaultFalse()->info('true = no tenant condition: every request of the class belongs to the one tenant (never with tenant_field).')->end()
                            ->scalarNode('archived_field')->defaultNull()->info('Nullable mapped field: a request where it is not null (archived, deleted) is never searched.')->end()
                            ->scalarNode('order_field')->defaultNull()->info('Mapped field ordering the results, newest first. null = id_field.')->end()
                            ->arrayNode('excluded_statuses')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->info('Stored statuses never searched (e.g. draft, cancelled).')->end()
                            ->scalarNode('route')->defaultNull()->info('Route of one request (the result URL). Required.')->end()
                            ->scalarNode('route_id_parameter')->defaultValue('id')->cannotBeEmpty()->info('Parameter of that route holding id_field.')->end()
                            ->arrayNode('route_parameters')->useAttributeAsKey('name')->scalarPrototype()->end()->defaultValue([])->info('Fixed parameters of that route.')->end()
                            ->scalarNode('see_all_route')->defaultNull()->info('The requests list route that filters by the "q" parameter (the "see all" link). null = no link.')->end()
                            ->arrayNode('see_all_parameters')->useAttributeAsKey('name')->scalarPrototype()->end()->defaultValue([])->info('Fixed parameters of that route.')->end()
                            ->scalarNode('label')->defaultValue(WorkflowRequestSearchOptions::DEFAULT_LABEL)->cannotBeEmpty()->info('Translation key of the group label.')->end()
                            ->scalarNode('label_domain')->defaultNull()->info('Its translation domain (null = NowoWorkflowBundle).')->end()
                            ->scalarNode('status_label_domain')->defaultNull()->info('Translation domain of the status labels. null = the stored status is shown.')->end()
                            ->scalarNode('status_label_prefix')->defaultValue('')->info('Prefix of the status label keys (key = prefix + stored status).')->end()
                            ->scalarNode('icon')->defaultValue('tabler:checklist')->info('UX Icons name of the rows (null = SearchKit\'s default).')->end()
                            ->booleanNode('accept_window_side_channel')->defaultFalse()->info('A sensitive subject needs a narrower (SQL filtering of what the viewer may not see). true = accept instead that rows hidden only by the access event take places in the candidate window, so a search can tell they exist (fewer results, an inexact total).')->end()
                            ->booleanNode('sensitive')->defaultTrue()->info('true (default): titles are private (e.g. absences), so the group is off while SearchKit keeps recents in the browser without revalidation.')->end()
                            ->integerNode('max_candidates')->defaultValue(100)->min(1)->max(WorkflowRequestSearchOptions::MAX_CANDIDATES)->info('The most matching requests one search reads and checks (newest first): bounds the access event, the visible total and the deepest results page.')->end()
                            ->integerNode('min_length')->defaultValue(0)->min(0)->info('Shortest text this group searches.')->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $v): array {
                    if ($v['enabled'] !== true) {
                        return $v;
                    }
                    if ($v['subjects'] === []) {
                        throw new InvalidConfigurationException('nowo_workflow.search_kit is enabled: add at least one entry under subjects.');
                    }
                    $groups = [];
                    foreach ($v['subjects'] as $subject => $config) {
                        try {
                            $group = WorkflowRequestSearchOptions::fromConfig((string) $subject, $config)->groupKey();
                        } catch (InvalidArgumentException $e) {
                            throw new InvalidConfigurationException($e->getMessage(), 0, $e);
                        }
                        if (isset($groups[$group])) {
                            throw new InvalidConfigurationException(sprintf('nowo_workflow.search_kit.subjects: the subjects "%s" and "%s" both use the group "%s".', $groups[$group], $subject, $group));
                        }
                        $groups[$group] = (string) $subject;
                    }

                    return $v;
                })
            ->end();

        return $node;
    }

    private function serviceIdNode(string $name, string $info): NodeDefinition
    {
        return (new TreeBuilder($name, 'scalar'))->getRootNode()
            ->info($info)
            ->defaultNull()
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || $v === ''))
                ->thenInvalid('Expected a service id or null; got %s.')
            ->end();
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
