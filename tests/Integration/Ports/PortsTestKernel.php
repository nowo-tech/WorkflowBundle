<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Tests\Integration\Ddd\Domain\PurchaseOrder;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughPolicyDecorator;
use Nowo\WorkflowBundle\Tests\Support\Access\PolicyPredicate;
use Nowo\WorkflowBundle\Tests\Support\Access\TestTokenListener;
use Nowo\WorkflowBundle\Tests\Support\Removed\LegacyOnlyUiAccessChecker;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host shapes for the access port and the persistence paths (one cache directory per variant):
 *
 * - `builtin`: no access_checker, the built-in role-based policy (access_roles ROLE_ADMIN);
 * - `new`: access_checker on a service implementing the access policy port;
 * - `policy-decorator`: the built-in policy with a host decorator on the port (it wraps the role-based policy);
 * Every variant also maps the host aggregate {@see PurchaseOrder}
 * (on the default manager) and takes `runtime.flush_after_apply` from the constructor (null = not set: false since
 * 2.0).
 *
 * - `ddd`: a DDD / hexagonal host: plain-PHP aggregate mapped in XML, the bundle entities mapped from the shipped XML
 *   on a dedicated `workflow` entity manager (register_mapping: false), the host's own policy aliased to the 1.14
 *   port, flush_after_apply: false.
 *
 * Persistence shapes (audit NF2-1 / NF2-3):
 *
 * - `main-default`: the application's default manager is `main` (connection `main`); the bundle mapping lands on a
 *   manager named `default` (connection `default`), as in 1.13; without `doctrine.entity_manager` the build fails
 *   since 2.0 (Q5), `main-default-explicit` sets it to `default`;
 * - `mapped-elsewhere`: register_mapping: false, the host maps the bundle XML on a `workflow` manager only; without
 *   `doctrine.entity_manager` the build fails since 2.0 (Q5), `mapped-elsewhere-explicit` sets it to `workflow`;
 * - `host-mapping-pass`: register_mapping: false, the host maps the bundle XML with a DoctrineOrmMappingsPass on the
 *   default manager (derived, no option needed).
 *
 * Paths removed in 2.0, each of which must fail the build: `removed-checker` (access_checker on a class that still
 * implements the removed request-based port), `removed-checker-decorator`, `removed-registry-decorator` and
 * `removed-versioning-decorator` (host decorators of the removed ports), `private-false`
 * (`doctrine.private_entity_manager: false`), and `discovered-ui` / `discovered-registry` / `discovered-versioning`
 * (application classes that still implement a removed port, registered by resource discovery with `#[AsDecorator]` /
 * `#[AsAlias]`: they cannot be loaded, audit XR-WF-M1).
 *
 * SecurityBundle is not installed: the kernel registers `security.token_storage` and `security.authorization_checker`
 * itself, and {@see TestTokenListener} stands in for the firewall.
 */
final class PortsTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const CACHE_ROOT = '/nowo-workflow-ports-kernel';

    public const VARIANTS = ['builtin', 'new', 'policy-decorator', 'ddd', 'main-default', 'main-default-explicit', 'mapped-elsewhere', 'mapped-elsewhere-explicit', 'host-mapping-pass', 'removed-checker', 'removed-checker-decorator', 'removed-registry-decorator', 'removed-versioning-decorator', 'private-false', 'discovered-ui', 'discovered-registry', 'discovered-versioning'];

    public function __construct(private readonly string $variant, bool $debug = true, private readonly ?bool $flushAfterApply = null)
    {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new NowoWorkflowBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        $flush = match ($this->flushAfterApply) {
            null  => '',
            true  => '-flush',
            false => '-noflush',
        };

        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache/' . $this->variant . ($this->debug ? '-debug' : '') . $flush;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'ports_kernel_test_secret',
            'test'                 => true,
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'translator'           => ['fallbacks' => ['en']],
        ]);

        $orm = [];
        if (PHP_VERSION_ID >= 80400 && str_starts_with((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '2.')) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $workflow = [
            'security' => ['access_roles' => ['ROLE_ADMIN']],
        ];
        if ($this->flushAfterApply !== null) {
            $workflow['runtime'] = ['flush_after_apply' => $this->flushAfterApply];
        }

        if ($this->variant === 'main-default' || $this->variant === 'main-default-explicit') {
            $container->extension('doctrine', [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'main' => ['url' => 'sqlite:///:memory:']]],
                'orm'  => $orm + [
                    'default_entity_manager' => 'main',
                    'entity_managers'        => ['main' => ['connection' => 'main', 'mappings' => ['Host' => ['type' => 'xml', 'dir' => __DIR__ . '/../Ddd/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Ddd\\Domain', 'is_bundle' => false]]]],
                ],
            ]);
            if ($this->variant === 'main-default-explicit') {
                $workflow['doctrine'] = ['entity_manager' => 'default'];
            }
        } elseif ($this->variant === 'mapped-elsewhere' || $this->variant === 'mapped-elsewhere-explicit') {
            $container->extension('doctrine', [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'workflow' => ['url' => 'sqlite:///:memory:']]],
                'orm'  => $orm + [
                    'default_entity_manager' => 'default',
                    'entity_managers'        => [
                        'default'  => ['connection' => 'default', 'mappings' => ['Host' => ['type' => 'xml', 'dir' => __DIR__ . '/../Ddd/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Ddd\\Domain', 'is_bundle' => false]]],
                        'workflow' => ['connection' => 'workflow', 'mappings' => ['NowoWorkflowBundle' => ['type' => 'xml', 'dir' => NowoWorkflowExtension::XML_MAPPING_DIR, 'prefix' => 'Nowo\\WorkflowBundle\\Entity', 'is_bundle' => false]]],
                    ],
                ],
            ]);
            $workflow['doctrine'] = ['register_mapping' => false] + ($this->variant === 'mapped-elsewhere-explicit' ? ['entity_manager' => 'workflow'] : []);
        } elseif ($this->variant === 'host-mapping-pass') {
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm'  => $orm + ['mappings' => ['Host' => ['type' => 'xml', 'dir' => __DIR__ . '/../Ddd/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Ddd\\Domain', 'is_bundle' => false]]],
            ]);
            $workflow['doctrine'] = ['register_mapping' => false];
        } elseif ($this->variant === 'ddd') {
            $container->extension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections'        => [
                        'default'  => ['url' => 'sqlite:///:memory:'],
                        'workflow' => ['url' => 'sqlite:///:memory:'],
                    ],
                ],
                'orm' => $orm + [
                    'default_entity_manager' => 'default',
                    'entity_managers'        => [
                        'default' => [
                            'connection' => 'default',
                            'mappings'   => [
                                'Host' => ['type' => 'xml', 'dir' => __DIR__ . '/../Ddd/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Ddd\\Domain', 'is_bundle' => false],
                            ],
                        ],
                        'workflow' => [
                            'connection' => 'workflow',
                            'mappings'   => [
                                'NowoWorkflowBundle' => ['type' => 'xml', 'dir' => NowoWorkflowExtension::XML_MAPPING_DIR, 'prefix' => 'Nowo\\WorkflowBundle\\Entity', 'is_bundle' => false],
                            ],
                        ],
                    ],
                ],
            ]);
            $workflow['connection'] = 'workflow';
            $workflow['doctrine']   = ['entity_manager' => 'workflow', 'register_mapping' => false];
        } else {
            // Standard host: one default manager, the bundle's attribute mapping (prepended) next to the host's own.
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm'  => $orm + ['mappings' => [
                    'Host' => ['type' => 'xml', 'dir' => __DIR__ . '/../Ddd/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Ddd\\Domain', 'is_bundle' => false],
                ]],
            ]);
        }

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set('security.token_storage', TokenStorage::class)->public();
        $services->set('security.authorization_checker', AuthorizationChecker::class)
            ->args([
                service('security.token_storage'),
                inline_service(AccessDecisionManager::class)->args([[inline_service(RoleHierarchyVoter::class)->args([inline_service(RoleHierarchy::class)->args([[]])])]]),
            ])
            ->public();
        // Test-only public aliases (the services are private and some would be removed as unused).
        foreach ([WorkflowApplicator::class, WorkflowResolver::class, DemoSeedService::class] as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
        $services->set(TestTokenListener::class)->args([service('security.token_storage')])->tag('kernel.event_subscriber');

        switch ($this->variant) {
            case 'new':
                $services->set('app.workflow_policy', PolicyPredicate::class)->args(['non_impersonated_admin']);
                $workflow['security']['access_checker'] = 'app.workflow_policy';
                break;
            case 'policy-decorator':
                $services->set('app.workflow_policy_audit', PassThroughPolicyDecorator::class)
                    ->decorate(WorkflowUiAccessPolicyInterface::class)
                    ->args([service('.inner')])
                    ->public();
                break;
            case 'removed-checker':
                $services->set('app.workflow_checker', LegacyOnlyUiAccessChecker::class);
                $workflow['security']['access_checker'] = 'app.workflow_checker';
                break;
            case 'removed-checker-decorator':
                $services->set('app.workflow_audit', PassThroughPolicyDecorator::class)->decorate(RemovedPortsPass::UI_ACCESS_CHECKER)->args([service('.inner')]);
                break;
            case 'removed-registry-decorator':
                $services->set('app.workflow_registry_audit', PassThroughPolicyDecorator::class)->decorate(RemovedPortsPass::REGISTRY)->args([service('.inner')]);
                break;
            case 'removed-versioning-decorator':
                $services->set('app.workflow_versioning_audit', PassThroughPolicyDecorator::class)->decorate(RemovedPortsPass::VERSIONING)->args([service('.inner')]);
                break;
            case 'private-false':
                $workflow['doctrine'] = ['private_entity_manager' => false];
                break;
            case 'discovered-ui':
            case 'discovered-registry':
            case 'discovered-versioning':
                $directory = ucfirst(substr($this->variant, 11));
                $services->defaults()->autowire()->autoconfigure();
                $services->load('Nowo\\WorkflowBundle\\Tests\\Support\\Removed\\Discovered\\' . $directory . '\\', dirname(__DIR__, 2) . '/Support/Removed/Discovered/' . $directory . '/');
                break;
            case 'ddd':
                $services->set('app.workflow_policy', PolicyPredicate::class)->args(['non_impersonated_admin']);
                $services->alias(WorkflowUiAccessPolicyInterface::class, 'app.workflow_policy');
                break;
        }

        $container->extension('nowo_workflow', $workflow);
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->variant === 'host-mapping-pass') {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createXmlMappingDriver([NowoWorkflowExtension::XML_MAPPING_DIR => 'Nowo\\WorkflowBundle\\Entity']));
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoWorkflowBundle/Resources/config/routes.yaml');
    }
}
