<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function sys_get_temp_dir;

/**
 * Kernel whose routing is the Symfony default `controllers: { resource: routing.controllers }`, optionally plus the
 * documented import of the bundle `routes.yaml` (REQ-SF-004 rule 7).
 */
final class HostControllersRoutesKernel extends Kernel
{
    use MicroKernelTrait;

    public const CACHE_ROOT = '/nowo-workflow-host-controllers';

    public function __construct(private readonly bool $importBundleRoutes = false)
    {
        // Debug kernel: security.allow_unauthenticated (no SecurityBundle here) is meant for dev only.
        parent::__construct('test', true);
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
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache/' . ($this->importBundleRoutes ? 'imported' : 'default');
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'host_controllers_test_secret',
            'test'                 => true,
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'translator'           => ['fallbacks' => ['en']],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [],
        ]);
        $container->extension('nowo_workflow', [
            'web_ui'   => ['path_prefix' => '/workflow', 'default_locale' => 'en', 'locales' => ['en', 'es']],
            'security' => ['allow_unauthenticated' => true],
        ]);
        // Keep the debug kernel quiet (the default logger writes every event to stderr).
        $container->services()->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
        if ($this->importBundleRoutes) {
            $routes->import('@NowoWorkflowBundle/Resources/config/routes.yaml');
        }
    }
}
