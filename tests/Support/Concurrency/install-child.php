<?php

declare(strict_types=1);

/*
 * One racer of the real-database install race (REQ-RUNTIME-003): its own process and connection. It prints "ready",
 * waits for "go" on stdin, installs the template for the tenant and prints "created" or "existing". CLI only; never
 * loaded by a worker.
 *
 * Usage: php install-child.php <database url> <template key> <tenant key>
 */

use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\StaleReadDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$manager    = IntegrationEntityManagerFactory::createForParams(RaceHarness::urlParams((string) ($argv[1] ?? '')));
$repository = new StaleReadDefinitionRepository(new TestManagerRegistry($manager));
$catalog    = new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);

fwrite(\STDOUT, "ready\n");
fgets(\STDIN);

$installation = $catalog->installTemplate((string) ($argv[2] ?? ''), new WorkflowTenant((string) ($argv[3] ?? '')));
fwrite(\STDOUT, ($installation->created ? 'created' : 'existing') . "\n");
$manager->getConnection()->close();
