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
 * REQ-RUNTIME-003 rule 4 for the definition version numbers (since 2.2): two connections on one SQLite file (default
 * suite). The real-server variants are ConcurrentVersionMySqlTest and ConcurrentVersionPostgreSqlTest.
 */
final class ConcurrentVersionTest extends TestCase
{
    use ConcurrentVersionScenarios;

    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'wfver');
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
