<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\ArrayCsrfTokenStorage;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function md5;
use function str_starts_with;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.15.2: a host on MySQL / MariaDB (server_version configured, no database needed to generate the schema) with the
 * installed DoctrineBundle (2.x on the Symfony 7.4 CI cells, 3.x on the Symfony 8.1 ones).
 */
final class DoctrineListenersTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $url, private readonly string $serverVersion, private readonly ?string $tablePrefix = null)
    {
        parent::__construct('test', false);
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
        return sys_get_temp_dir() . '/nowo-workflow-doctrine-listeners/' . md5($this->url . '|' . $this->serverVersion . '|' . $this->tablePrefix);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-workflow-doctrine-listeners/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'doctrine_listeners_kernel_test',
            'test'                 => true,
            'session'              => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
        ]);
        $container->services()->set('security.csrf.token_storage', ArrayCsrfTokenStorage::class);

        $orm = [];
        if (PHP_VERSION_ID >= 80400 && str_starts_with((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '2.')) {
            $orm['enable_native_lazy_objects'] = true;
        }
        $container->extension('doctrine', [
            'dbal' => ['url' => $this->url, 'server_version' => $this->serverVersion],
            'orm'  => $orm,
        ]);
        $container->extension('nowo_workflow', ['web_ui' => ['enabled' => false]] + ($this->tablePrefix === null ? [] : ['table_prefix' => $this->tablePrefix]));
    }
}
