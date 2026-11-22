<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection;

use Nowo\UiKitBundle\Theme\ThemeSlots;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Audit\AuditMiddleware;
use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\Collation\WorkflowExactKeyColumns;
use Nowo\WorkflowBundle\Contract\WorkflowDefinitionCatalogInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Controller\DashboardController;
use Nowo\WorkflowBundle\Controller\WorkflowDefinitionController;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Doctrine\WorkflowManagerRegistry;
use Nowo\WorkflowBundle\EventListener\TenantIsolationGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\TransitionAuditSubscriber;
use Nowo\WorkflowBundle\EventListener\TransitionGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\WorkflowUiRouteContextSubscriber;
use Nowo\WorkflowBundle\EventListener\WorkflowUnitOfWorkSubscriber;
use Nowo\WorkflowBundle\Http\WorkflowUiTenancy;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;
use Nowo\WorkflowBundle\Tenancy\CloneStructureLock;
use Nowo\WorkflowBundle\Tenancy\SecurityTokenActorProvider;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function class_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function method_exists;

/**
 * Loads bundle services and exposes configuration as container parameters.
 */
final class NowoWorkflowExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Public path of src/Resources/public after `assets:install` (base_path of the nowo_workflow asset package).
     */
    public const ASSETS_BASE_PATH = '/bundles/nowoworkflow';

    /**
     * Directory of the XML mapping of the bundle entities (1.14, same tables and columns as the attributes).
     */
    public const XML_MAPPING_DIR = __DIR__ . '/../Resources/config/doctrine';

    /** Since 1.21: SearchKit's `EntitySearchDefinition` (a string: SearchKit is optional). */
    public const SEARCH_KIT_DEFINITION = 'Nowo\\SearchKitBundle\\Provider\\Entity\\EntitySearchDefinition';

    /** Since 1.21: SearchKit's `EntitySearchDefinition::TAG`. */
    public const SEARCH_KIT_DEFINITION_TAG = 'nowo_search_kit.entity_definition';

    /** Since 1.21: service id prefix of the SearchKit definitions (`nowo_workflow.search_kit.<subject>`). */
    public const SEARCH_KIT_SERVICE_PREFIX = 'nowo_workflow.search_kit.';

    /**
     * Prepends Doctrine mappings, translator paths, the asset package and FormKit / UiKit defaults.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        [$registerMapping, $mappingType] = $this->rawMappingOptions($container);
        if ($registerMapping && $container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoWorkflowBundle' => [
                            'type'      => $mappingType,
                            'dir'       => $mappingType === 'xml' ? self::XML_MAPPING_DIR : __DIR__ . '/../Entity',
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
                // Named asset package of the published files (REQ-ASSETS-004): asset('css/nowo-workflow.css', 'nowo_workflow').
                'assets' => [
                    'packages' => [
                        Configuration::ALIAS => [
                            'base_path' => self::ASSETS_BASE_PATH,
                        ],
                    ],
                ],
            ]);
        }

    }

    /**
     * `doctrine.register_mapping` and `doctrine.mapping_type` from the raw configuration (prepend() runs before the
     * configuration is processed; the last file that sets a key wins, invalid values are rejected later by load()).
     *
     * @return array{0: bool, 1: string}
     */
    private function rawMappingOptions(ContainerBuilder $container): array
    {
        $register = true;
        $type     = 'attribute';
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $doctrine = is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (is_bool($doctrine['register_mapping'] ?? null)) {
                $register = $doctrine['register_mapping'];
            }
            if (in_array($doctrine['mapping_type'] ?? null, ['attribute', 'xml'], true)) {
                $type = $doctrine['mapping_type'];
            }
        }

        return [$register, $type];
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
     * CSS stack the Workflow pages render with: the host's nowo_ui_kit.css_framework, else UiKit's default
     * (bootstrap5).
     */
    private function resolvedCssFramework(ContainerBuilder $container): string
    {
        $framework = 'bootstrap5';
        if ($container->hasExtension('nowo_ui_kit')) {
            foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
                if (is_array($cfg) && is_string($cfg['css_framework'] ?? null)) {
                    $framework = $cfg['css_framework'];
                }
            }
        }

        return $framework;
    }

    /**
     * Loads the bundle services and exposes the processed configuration as container parameters.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);
        $webUi  = $config['web_ui'];

        $container->setParameter(Configuration::ALIAS . '.enabled', $webUi['enabled']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.runtime.flush_after_apply', $config['runtime']['flush_after_apply']);
        $container->setParameter(Configuration::ALIAS . '.runtime.concurrency.default', $config['runtime']['concurrency']['default']);
        $container->setParameter(Configuration::ALIAS . '.runtime.concurrency.workflows', $config['runtime']['concurrency']['workflows']);
        $container->setParameter(Configuration::ALIAS . '.runtime.concurrency.retry_after', $config['runtime']['concurrency']['retry_after']);
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, $config['doctrine']['entity_manager']);
        $this->registerPrivateEntityManager($container);
        // Filled by FrameworkWorkflowNamesPass with the framework.workflows names.
        $container->setParameter(Configuration::ALIAS . '.framework_workflow_names', []);

        $container->setParameter(Configuration::ALIAS . '.web_ui.enabled', $webUi['enabled']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.path_prefix', $webUi['path_prefix']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.templates', $webUi['templates']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.list_page_size', $webUi['list_page_size']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.default_locale', $webUi['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.locales', $webUi['locales']);

        $this->registerTenancy($container, $config);
        $this->registerExactKeyColumns($container);
        $this->registerSearchKit($container, $config['search_kit']);

        $container->setParameter(Configuration::ALIAS . '.security.access_roles', $config['security']['access_roles']);
        $container->setParameter(Configuration::ALIAS . '.security.access_checker', $config['security']['access_checker']);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
    }

    /**
     * Since 1.14.0, the only mode since 2.0 (REQ-ARCH-001 rule 6): the repository, the admin controller and the demo
     * seeder read and write the definitions through the bundle's private entity manager ({@see WorkflowEntityManager}), so a definition save
     * never flushes, and a failed one never resets, an application manager. The manager is dropped at every outermost
     * unit-of-work boundary ({@see WorkflowUnitOfWorkSubscriber}) and at `kernel.reset`; the rollback middleware is
     * attached to its connection by {@see PrivateEntityManagerPass} (doctrine/dbal 4 only).
     */
    private function registerPrivateEntityManager(ContainerBuilder $container): void
    {
        $container->register(WorkflowEntityManager::class, WorkflowEntityManager::class)
            ->setArguments([new Reference('doctrine'), '%' . PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER . '%', new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(WorkflowManagerRegistry::class, WorkflowManagerRegistry::class)
            ->setArguments([new Reference('doctrine'), new Reference(WorkflowEntityManager::class)]);

        $container->getDefinition(WorkflowDefinitionRepository::class)
            ->setArgument('$registry', new Reference(WorkflowManagerRegistry::class));
        $container->getDefinition(WorkflowDefinitionController::class)
            ->setArgument('$entityManager', new Reference(WorkflowEntityManager::class))
            // The private manager recreates itself when closed: the application's managers are never reset.
            ->setArgument('$managerRegistry', null);
        $container->getDefinition(DemoSeedService::class)
            ->setArgument('$entityManager', new Reference(WorkflowEntityManager::class));

        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $container->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class)
                ->setArguments([new Reference(WorkflowEntityManager::class)])
                ->addTag('doctrine.middleware');
        }

        $container->getDefinition(WorkflowUnitOfWorkSubscriber::class)
            ->setArgument('$resettables', new IteratorArgument([
                new Reference(WorkflowEntityManager::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
            ]));
    }

    /**
     * Since 1.15: tenancy, transition guards, versioning and the audit event (docs/MULTITENANCY.md). Without
     * `tenancy.tenant_resolver`, the guard ports or `audit.enabled`, nothing here changes a 1.14 behaviour: the
     * services are wired with no port and answer as 1.14 did.
     *
     * @param array<string, mixed> $config
     */
    private function registerTenancy(ContainerBuilder $container, array $config): void
    {
        /** @var array{tenant_resolver: string|null, subject_reference_resolver: string|null, actor_provider: string|null, subject_classes: array<string, string>, lock_clone_structure: bool, label_domains: list<string>, ui: array{tenant_resolver: string|null, action_checker: string|null}} $tenancy */
        $tenancy = $config['tenancy'];
        /** @var array{permission_checker: string|null, variables_provider: string|null, max_automatic_transitions: int} $guards */
        $guards = $config['guards'];
        /** @var array{enabled: bool|null} $audit */
        $audit   = $config['audit'];
        $enabled = $tenancy['tenant_resolver'] !== null;
        $auditOn = $audit['enabled'] ?? $enabled;
        $ref     = static fn (?string $id): ?Reference => $id === null ? null : new Reference($id);

        $container->setParameter(Configuration::ALIAS . '.tenancy.enabled', $enabled);
        $container->setParameter(Configuration::ALIAS . '.audit.enabled', $auditOn);

        $container->register(SecurityTokenActorProvider::class, SecurityTokenActorProvider::class)
            ->setArguments([new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->register(TenancyContext::class, TenancyContext::class)
            ->setArguments([
                $ref($tenancy['tenant_resolver']),
                $ref($tenancy['subject_reference_resolver']),
                $ref($tenancy['actor_provider']) ?? new Reference(SecurityTokenActorProvider::class),
            ]);
        $container->register(SubjectPinStore::class, SubjectPinStore::class)->setAutowired(true);
        $container->register(CloneStructureLock::class, CloneStructureLock::class)
            ->setArguments([new Reference(WorkflowDefinitionRepository::class), $enabled && $tenancy['lock_clone_structure']]);
        $container->register(WorkflowDefinitionLocator::class, WorkflowDefinitionLocator::class)->setAutowired(true)
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        // Internal since 2.0: the admin UI and WorkflowVersionManager call it with the definitions they loaded.
        $container->register(WorkflowVersioning::class, WorkflowVersioning::class)->setAutowired(true)->setPublic(false)
            ->setArgument('$lockCloneStructure', $enabled && $tenancy['lock_clone_structure'])
            // Since 2.2: the Retry-After of a conflict while claiming a version number.
            ->setArgument('$retryAfterSeconds', '%nowo_workflow.runtime.concurrency.retry_after%');
        // Since 1.19, the only versioning port since 2.0 (REQ-ARCH-001 rule 1): hosts call it, and decorate or replace
        // it to change what their callers get.
        $container->register(WorkflowVersionManager::class, WorkflowVersionManager::class)->setAutowired(true)->setPublic(false);
        $container->setAlias(WorkflowVersionManagerInterface::class, WorkflowVersionManager::class);
        // Since 2.1 (DDD-host checklist B7): list a tenant's definitions and install a template, as views and refs.
        $container->register(WorkflowDefinitionCatalog::class, WorkflowDefinitionCatalog::class)->setAutowired(true)->setPublic(false);
        $container->setAlias(WorkflowDefinitionCatalogInterface::class, WorkflowDefinitionCatalog::class);

        $container->getDefinition(WorkflowResolver::class)->setArgument('$tenancy', new Reference(TenancyContext::class));
        $container->getDefinition(DatabaseWorkflowRegistry::class)->setArgument('$locator', new Reference(WorkflowDefinitionLocator::class));
        $container->getDefinition(WorkflowApplicator::class)
            ->setArgument('$locator', new Reference(WorkflowDefinitionLocator::class))
            ->setArgument('$databaseRegistry', new Reference(DatabaseWorkflowRegistry::class))
            ->setArgument('$maxAutomaticTransitions', $guards['max_automatic_transitions'])
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $container->register(TransitionGuardSubscriber::class, TransitionGuardSubscriber::class)
            ->setArguments([
                new Reference(TenancyContext::class),
                $ref($guards['permission_checker']),
                $ref($guards['variables_provider']),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('kernel.event_subscriber');

        if ($enabled) {
            $container->register(TenantIsolationGuardSubscriber::class, TenantIsolationGuardSubscriber::class)
                ->setArguments([new Reference(WorkflowDefinitionLocator::class)])
                ->addTag('kernel.event_subscriber');
        }

        $container->register(WorkflowUiTenancy::class, WorkflowUiTenancy::class)
            ->setArguments([
                $enabled,
                $ref($tenancy['ui']['tenant_resolver']),
                $ref($tenancy['ui']['action_checker']),
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $ref($guards['permission_checker']),
                $ref($guards['variables_provider']),
                $tenancy['subject_classes'],
                $tenancy['label_domains'],
            ]);
        $container->register(WorkflowUiRouteContextSubscriber::class, WorkflowUiRouteContextSubscriber::class)
            ->setArguments([new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']);
        foreach ([WorkflowDefinitionController::class, DashboardController::class] as $controller) {
            $container->getDefinition($controller)->setArgument('$tenancy', new Reference(WorkflowUiTenancy::class));
        }

        if (!$auditOn) {
            return;
        }
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            throw new InvalidConfigurationException('nowo_workflow.audit needs doctrine/dbal 4 (the after-commit dispatch observes the transactions with a DBAL 4 middleware); set audit.enabled: false.'); // @codeCoverageIgnore
        }
        $container->register(AfterCommitDispatcher::class, AfterCommitDispatcher::class)
            ->setArguments([
                new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference(WorkflowUnitOfWorkSubscriber::class),
            ])
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(AuditMiddleware::class, AuditMiddleware::class)
            ->setArguments([new Reference(AfterCommitDispatcher::class)])
            ->addTag('doctrine.middleware');
        $container->register(TransitionAuditSubscriber::class, TransitionAuditSubscriber::class)
            ->setArguments([new Reference(AfterCommitDispatcher::class), new Reference(TenancyContext::class)])
            ->addTag('kernel.event_subscriber');
        $container->getDefinition(WorkflowApplicator::class)->setArgument('$audit', new Reference(AfterCommitDispatcher::class));
    }

    /**
     * Since 1.21: whether the installed SearchKit has the 1.10 provider base (EntitySearchDefinition::prepareItems()).
     * Without it (not installed, or 1.9 despite the composer conflict) no group is registered, so an older SearchKit
     * never meets a definition it cannot run.
     *
     * @param string $definitionClass SearchKit's EntitySearchDefinition (a parameter for the tests)
     */
    public static function supportsSearchKit(string $definitionClass = self::SEARCH_KIT_DEFINITION): bool
    {
        return class_exists($definitionClass) && method_exists($definitionClass, 'prepareItems');
    }

    /**
     * Since 1.21: one SearchKit group per `search_kit.subjects` entry ({@see WorkflowRequestSearchDefinition}), only
     * when `search_kit.enabled` and SearchKit >= 1.10 is installed (SearchKit stays optional: it needs PHP >= 8.3).
     * Each definition is tagged for SearchKit's provider pass, which builds its provider.
     *
     * @param array{enabled: bool, subjects: array<string, array<string, mixed>>} $searchKit
     */
    private function registerSearchKit(ContainerBuilder $container, array $searchKit): void
    {
        if (!$searchKit['enabled'] || !self::supportsSearchKit()) {
            return;
        }
        $optional = static fn (mixed $id): ?Reference => is_string($id) && $id !== '' ? new Reference($id) : null;
        foreach ($searchKit['subjects'] as $subject => $config) {
            $optionsId = self::SEARCH_KIT_SERVICE_PREFIX . $subject . '.options';
            $container->register($optionsId, WorkflowRequestSearchOptions::class)
                ->setFactory([WorkflowRequestSearchOptions::class, 'fromConfig'])
                ->setArguments([(string) $subject, $config]);
            $container->register(self::SEARCH_KIT_SERVICE_PREFIX . $subject, WorkflowRequestSearchDefinition::class)
                ->setArguments([
                    new Reference('doctrine'),
                    new Reference((string) $config['gate']),
                    new Reference('router'),
                    new Reference($optionsId),
                    new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    $optional($config['title_resolver'] ?? null),
                    $optional($config['narrower'] ?? null),
                    new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->addTag(self::SEARCH_KIT_DEFINITION_TAG, ['priority' => $config['priority'] ?? 0]);
        }
    }

    /**
     * REQ-SEC-006: declare the bundle's identity key columns, so a host's nowo:migrations:check-key-collations
     * asserts them against its real schema. The contract comes from nowo-tech/migrations-kit-contracts (interfaces
     * and value objects, no dependencies beyond PHP); the check itself lives in MigrationsKitBundle, which only the
     * host needs. A host without it simply never reads the tag.
     */
    private function registerExactKeyColumns(ContainerBuilder $container): void
    {
        $container->register(WorkflowExactKeyColumns::class, WorkflowExactKeyColumns::class)
            ->setArguments([new Reference(TableNamePrefixer::class)])
            ->addTag('nowo_migrations_kit.exact_key_columns');
    }

    /**
     * Returns the configuration alias (nowo_workflow).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
