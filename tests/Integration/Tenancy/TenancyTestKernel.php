<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\AnyUserTokenListener;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\ArrayCsrfTokenStorage;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\CountingRegistryDecorator;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\HostRuntime;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\HostTenancyPorts;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\RecordingAuditListener;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
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
 * Kernel of the 1.15 tenancy tests (no services resetter between requests: requests go straight to http_kernel).
 *
 * - `tenancy`: standard host, one SQLite connection, every tenancy / guard port and the audit event, the UI mounted
 *   under `/organizations/{uuid}/settings/workflow` (web_ui.path_prefix, Beacon's shape);
 * - `tenancy-ddd`: DDD host, the bundle's XML mapping on a dedicated `workflow` manager and connection, the host's
 *   subject on the default one;
 * - `plain`: no tenancy port at all (the 1.14 behaviour), UI at `/workflow`;
 * - `legacy-registry`: `plain` plus a 1.14 host decorator of the deprecated WorkflowRegistryInterface;
 * - `host-runtime`: `plain` plus the host's own WorkflowRuntimeInterface.
 */
final class TenancyTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const CACHE_ROOT = '/nowo-workflow-tenancy-kernel';

    public const PREFIX = '/organizations/{uuid}/settings';

    public function __construct(private readonly string $variant, bool $debug = false, private readonly bool $flushAfterApply = true)
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
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache/' . $this->variant . ($this->debug ? '-debug' : '') . ($this->flushAfterApply ? '-flush' : '');
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'tenancy_kernel_test_secret',
            'test'                 => true,
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'translator'           => ['fallbacks' => ['en']],
        ]);

        $orm = [];
        if (PHP_VERSION_ID >= 80400 && str_starts_with((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '2.')) {
            $orm['enable_native_lazy_objects'] = true;
        }
        $host = ['Host' => ['type' => 'xml', 'dir' => __DIR__ . '/mapping', 'prefix' => 'Nowo\\WorkflowBundle\\Tests\\Integration\\Tenancy\\Domain', 'is_bundle' => false]];

        $workflow = [
            'runtime'  => ['flush_after_apply' => $this->flushAfterApply],
            'security' => ['access_roles' => ['ROLE_ADMIN']],
        ];

        if ($this->variant === 'tenancy-ddd') {
            $container->extension('doctrine', [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'workflow' => ['url' => 'sqlite:///:memory:']]],
                'orm'  => $orm + [
                    'default_entity_manager' => 'default',
                    'entity_managers'        => [
                        'default'  => ['connection' => 'default', 'mappings' => $host],
                        'workflow' => ['connection' => 'workflow', 'mappings' => ['NowoWorkflowBundle' => ['type' => 'xml', 'dir' => NowoWorkflowExtension::XML_MAPPING_DIR, 'prefix' => 'Nowo\\WorkflowBundle\\Entity', 'is_bundle' => false]]],
                    ],
                ],
            ]);
            $workflow['connection'] = 'workflow';
            $workflow['doctrine']   = ['entity_manager' => 'workflow', 'register_mapping' => false];
        } else {
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm'  => $orm + ['mappings' => $host],
            ]);
        }

        $services = $container->services();
        $services->set('logger', RecordingLogger::class)->public();
        $services->set('security.token_storage', TokenStorage::class)->public();
        $services->set('security.authorization_checker', AuthorizationChecker::class)
            ->args([
                service('security.token_storage'),
                inline_service(AccessDecisionManager::class)->args([[inline_service(RoleHierarchyVoter::class)->args([inline_service(RoleHierarchy::class)->args([[]])])]]),
            ])
            ->public();
        $services->set(AnyUserTokenListener::class)->args([service('security.token_storage')])->tag('kernel.event_subscriber');
        $services->set('security.csrf.token_storage', ArrayCsrfTokenStorage::class)->public();
        $services->set(RecordingAuditListener::class)->tag('kernel.event_subscriber')->public();
        foreach ([WorkflowApplicator::class, WorkflowResolver::class, DatabaseWorkflowRegistry::class, WorkflowVersioning::class, SubjectPinStore::class] as $id) {
            $services->alias('test.' . $id, $id)->public();
        }

        $services->alias('test.' . WorkflowRuntimeInterface::class, WorkflowRuntimeInterface::class)->public();
        if ($this->variant === 'legacy-registry') {
            $services->set('app.workflow_registry_audit', CountingRegistryDecorator::class)
                ->decorate(WorkflowRegistryInterface::class)
                ->args([service('.inner')])
                ->public();
        }
        if ($this->variant === 'host-runtime') {
            $services->set('app.workflow_runtime', HostRuntime::class);
            $services->alias(WorkflowRuntimeInterface::class, 'app.workflow_runtime');
        }

        if (str_starts_with($this->variant, 'tenancy')) {
            $services->alias('test.' . AfterCommitDispatcher::class, AfterCommitDispatcher::class)->public();
            $services->set('app.workflow_tenancy', HostTenancyPorts::class);
            $workflow['tenancy'] = [
                'tenant_resolver'            => 'app.workflow_tenancy',
                'subject_reference_resolver' => 'app.workflow_tenancy',
                'subject_classes'            => [LeaveRequest::class => 'status'],
                'ui'                         => ['tenant_resolver' => 'app.workflow_tenancy', 'action_checker' => 'app.workflow_tenancy'],
            ];
            $workflow['web_ui'] = ['path_prefix' => self::PREFIX . '/workflow'];
            $workflow['guards'] = ['permission_checker' => 'app.workflow_tenancy', 'variables_provider' => 'app.workflow_tenancy', 'max_automatic_transitions' => 5];
        }

        $container->extension('nowo_workflow', $workflow);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // The host prefix with its variables is the bundle's web_ui.path_prefix.
        $routes->import('@NowoWorkflowBundle/Resources/config/routes.yaml');
    }
}
