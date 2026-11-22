<?php

declare(strict_types=1);

/*
 * One process of the real-database definition version races (REQ-RUNTIME-003 rule 4, since 2.2): its own process and
 * connection. CLI only; never loaded by a worker.
 *
 * Usage:
 *   php version-child.php claim <database url> <slug> <count>
 *     prints "ready", waits for "go", inserts <count> drafts of the slug's published version through
 *     WorkflowDefinitionRepository::insertNextVersion() and prints their version numbers ("2,4,5"), or
 *     "error:<exception class>" at the first failure.
 *   php version-child.php hold <database url> <table> <own slug> <other slug>
 *     opens a transaction, locks the counter row of <own slug>, writes 200 ballast counter rows (so the server picks
 *     the other transaction as the deadlock victim), prints "ready", waits for "go", waits 0.5 s, then updates the
 *     counter row of <other slug> (held by the parent), commits and prints "ok" (or "error:<exception class>").
 */

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode    = (string) ($argv[1] ?? '');
$manager = IntegrationEntityManagerFactory::createForParams(RaceHarness::urlParams((string) ($argv[2] ?? '')));
$db      = $manager->getConnection();

try {
    if ($mode === 'claim') {
        $slug       = (string) ($argv[3] ?? '');
        $repository = new WorkflowDefinitionRepository(new TestManagerRegistry($manager));
        $source     = $repository->findCurrent($slug, null);
        if (!$source instanceof WorkflowDefinition) {
            throw new RuntimeException('No published version.');
        }
        fwrite(\STDOUT, "ready\n");
        fgets(\STDIN);
        $versions = [];
        for ($i = 0, $count = (int) ($argv[4] ?? 1); $i < $count; ++$i) {
            $versions[] = $repository->insertNextVersion($slug, null, static function (int $version) use ($repository, $source): int {
                $repository->save($source->copyAsDraft(null, $version));

                return $version;
            });
        }
        fwrite(\STDOUT, implode(',', $versions) . "\n");
    } elseif ($mode === 'hold') {
        [$table, $own, $other] = [(string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? '')];
        if ($db->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
        }
        $db->beginTransaction();
        $db->executeStatement('UPDATE ' . $table . " SET last_version = last_version + 1 WHERE tenant_key = '' AND slug = ?", [$own]);
        for ($i = 0; $i < 200; ++$i) {
            $db->insert($table, ['tenant_key' => 'ballast', 'slug' => 'ballast_' . $i, 'last_version' => 1]);
        }
        fwrite(\STDOUT, "ready\n");
        fgets(\STDIN);
        usleep(500_000);
        $db->executeStatement('UPDATE ' . $table . " SET last_version = last_version + 1 WHERE tenant_key = '' AND slug = ?", [$other]);
        $db->commit();
        fwrite(\STDOUT, "ok\n");
    }
} catch (Throwable $exception) {
    fwrite(\STDOUT, 'error:' . $exception::class . "\n");
} finally {
    $db->close();
}
