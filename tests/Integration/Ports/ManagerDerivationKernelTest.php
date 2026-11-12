<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ports;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function sys_get_temp_dir;

use const E_USER_DEPRECATED;

/**
 * Audit NF2-1 / NF2-3: without `doctrine.entity_manager`, the private entity manager uses the manager that maps the
 * bundle entities (the 1.13 `getManagerForClass()` rule), derived at build time, in booted kernels:
 *
 * - scenario A: the application's default manager is `main`, the bundle mapping is on a manager named `default`;
 * - scenario B: register_mapping: false and the bundle mapped on a `workflow` manager only;
 * - a host DoctrineOrmMappingsPass mapping the bundle (added after the bundle's own passes run) does not false-fail.
 *
 * The definitions are written on that manager's connection and the UI reads them.
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
     * @return iterable<string, array{string, string, string|null, bool}>
     */
    public static function shapes(): iterable
    {
        yield 'scenario A: default manager "main", bundle on "default"' => ['main-default', 'default', 'main', true];
        yield 'scenario B: bundle mapped on "workflow" only' => ['mapped-elsewhere', 'workflow', 'default', true];
        yield 'host DoctrineOrmMappingsPass' => ['host-mapping-pass', 'default', null, false];
    }

    #[Test]
    #[DataProvider('shapes')]
    public function theDefinitionsLiveOnTheManagerThatMapsThem(string $variant, string $expected, ?string $other, bool $deprecated): void
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

            $matching = 0;
            foreach ($deprecations as $message) {
                if (str_contains($message, 'nowo_workflow.doctrine.entity_manager: ' . $expected)) {
                    ++$matching;
                }
            }
            self::assertSame($deprecated ? 1 : 0, $matching, 'a derived manager that is not the default one asks for the explicit option');

            self::assertSame(200, self::send($kernel, '/workflow/definitions', 'admin')->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
