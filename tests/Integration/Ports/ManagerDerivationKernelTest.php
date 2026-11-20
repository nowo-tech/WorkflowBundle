<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function sys_get_temp_dir;

use const E_USER_DEPRECATED;

/**
 * Audit NF2-1 / NF2-3: the private entity manager uses the manager that maps the bundle entities, in booted kernels:
 *
 * - scenario A: the application's default manager is `main`, the bundle mapping is on a manager named `default`;
 * - scenario B: register_mapping: false and the bundle mapped on a `workflow` manager only;
 * - a host DoctrineOrmMappingsPass mapping the bundle on the default manager (added after the bundle's own passes
 *   run) does not false-fail and needs no option.
 *
 * The definitions are written on that manager's connection and the UI reads them. Since 2.0 (Q5) scenarios A and B
 * need `doctrine.entity_manager`: without it the build fails and names the option to set (1.14 to 1.22 derived the
 * manager with a deprecation).
 */
final class ManagerDerivationKernelTest extends TestCase
{
    use PortsKernelSupport;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . PortsTestKernel::CACHE_ROOT);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function shapes(): iterable
    {
        yield 'scenario A: default manager "main", bundle on "default"' => ['main-default-explicit', 'default', 'main'];
        yield 'scenario B: bundle mapped on "workflow" only' => ['mapped-elsewhere-explicit', 'workflow', 'default'];
        yield 'host DoctrineOrmMappingsPass' => ['host-mapping-pass', 'default', null];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function shapesWithoutTheOption(): iterable
    {
        yield 'scenario A' => ['main-default', 'default', 'main'];
        yield 'scenario B' => ['mapped-elsewhere', 'workflow', 'default'];
    }

    /**
     * 2.0 (Q5): a mapping manager that is not the default one fails the build without `doctrine.entity_manager`.
     */
    #[Test]
    #[DataProvider('shapesWithoutTheOption')]
    public function aNonDefaultMappingManagerWithoutTheOptionFailsTheBuild(string $variant, string $manager, string $default): void
    {
        $kernel = new PortsTestKernel($variant, false);

        try {
            $kernel->boot();
            self::fail('The build must fail without nowo_workflow.doctrine.entity_manager.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString(sprintf('mapped on the Doctrine entity manager "%s", which is not the default one ("%s")', $manager, $default), $exception->getMessage());
            self::assertStringContainsString('set nowo_workflow.doctrine.entity_manager: ' . $manager, $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    #[DataProvider('shapes')]
    public function theDefinitionsLiveOnTheManagerThatMapsThem(string $variant, string $expected, ?string $other): void
    {
        $deprecations = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        $kernel = new PortsTestKernel($variant, false);
        try {
            $kernel->boot();
        } finally {
            restore_error_handler();
        }

        try {
            self::createSchemas($kernel);
            self::seedPurchaseOrderFlow($kernel);
            $doctrine = self::testContainer($kernel)->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);

            $connection = $doctrine->getConnection($expected);
            self::assertInstanceOf(Connection::class, $connection);
            self::assertSame($connection, self::definitionsManager($kernel)->getConnection());
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_definition'));
            if ($other !== null) {
                $otherConnection = $doctrine->getConnection($other);
                self::assertInstanceOf(Connection::class, $otherConnection);
                self::assertNotSame($connection, $otherConnection);
            }

            foreach ($deprecations as $message) {
                self::assertStringNotContainsString('nowo-tech/workflow-bundle', $message, 'no Workflow deprecation in 2.0');
            }

            self::assertSame(200, self::send($kernel, '/workflow/definitions', 'admin')->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
