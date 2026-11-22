<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use PHPUnit\Framework\TestCase;

use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * REQ-RUNTIME-003 for the template install: two connections on one SQLite file (default suite). The real-server
 * variants are ConcurrentInstallMySqlTest and ConcurrentInstallPostgreSqlTest.
 */
final class ConcurrentInstallTest extends TestCase
{
    use ConcurrentInstallScenarios;

    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'wfinst');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    protected function connect(): EntityManagerInterface
    {
        return IntegrationEntityManagerFactory::createForParams(RaceHarness::sqliteParams($this->path));
    }
}
