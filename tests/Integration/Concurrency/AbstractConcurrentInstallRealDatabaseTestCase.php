<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_open;
use function sort;
use function stream_set_timeout;
use function trim;

use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 for the template install against a real server: the scenarios of ConcurrentInstallScenarios plus two
 * processes racing for real.
 */
abstract class AbstractConcurrentInstallRealDatabaseTestCase extends TestCase
{
    use ConcurrentInstallScenarios;

    protected string $url = '';

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $url = getenv(static::urlVariable());
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(static::urlVariable() . ' is not set (real-database race tests).');
        }
        $this->url = $url;
    }

    protected function connect(): EntityManagerInterface
    {
        return IntegrationEntityManagerFactory::createForParams(RaceHarness::urlParams($this->url));
    }

    #[Test]
    public function twoProcessesInstallingTheSameTemplateLeaveOneDefinition(): void
    {
        [$a, $tool, $metadata] = $this->prepare();

        try {
            $racers = [$this->racer('org-a'), $this->racer('org-a')];
            foreach ($racers as [, $pipes]) {
                self::assertSame('ready', trim((string) fgets($pipes[1])));
            }
            foreach ($racers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($racers as [$process, $pipes]) {
                $results[] = trim((string) fgets($pipes[1]));
                fclose($pipes[1]);
                proc_close($process);
            }
            sort($results);

            self::assertSame(['created', 'existing'], $results);
            self::assertSame(1, $this->tenantRows($a, 'org-a'));
        } finally {
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function racer(string $tenant): array
    {
        $script = dirname(__DIR__, 2) . '/Support/Concurrency/install-child.php';
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (test-only CLI child; its reads are bounded below)
        $process = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->url) . ' ' . escapeshellarg(self::TEMPLATE) . ' ' . escapeshellarg($tenant), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        stream_set_timeout($pipes[1], 30);

        return [$process, $pipes];
    }
}
