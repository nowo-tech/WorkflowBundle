<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Composer\InstalledVersions;
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
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function str_starts_with;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Booted host with an autoconfigured #[AsEventListener] guard and one framework.workflows workflow, to check that
 * the database workflows dispatch on the application event dispatcher and that slug collisions are refused.
 */
final class WorkflowEventsKernel extends Kernel
{
    use MicroKernelTrait;

    public const CACHE_ROOT = '/nowo-workflow-events-kernel';

    public const FRAMEWORK_WORKFLOW = 'kr_framework_flow';

    public function __construct()
    {
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
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'workflow_events_test_secret',
            'test'                 => true,
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'translator'           => ['fallbacks' => ['en']],
            'workflows'            => [
                self::FRAMEWORK_WORKFLOW => [
                    'type'            => 'state_machine',
                    'marking_store'   => ['type' => 'method', 'property' => 'status'],
                    'supports'        => [GuardProbeSubject::class],
                    'initial_marking' => 'draft',
                    'places'          => ['draft', 'done'],
                    'transitions'     => ['finish' => ['from' => 'draft', 'to' => 'done']],
                ],
            ],
        ]);
        $orm = [];
        if (PHP_VERSION_ID >= 80400 && str_starts_with((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '2.')) {
            $orm['enable_native_lazy_objects'] = true;
        }
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => $orm,
        ]);
        $container->extension('nowo_workflow', [
            'security' => ['allow_unauthenticated' => true],
        ]);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->set(GuardProbeListener::class)->autoconfigure()->public();
    }
}
