<?php

declare(strict_types=1);

/*
 * One racer of the real-database race tests (REQ-RUNTIME-003): its own process and connection. It loads the subject,
 * prints "ready", waits for "go" on stdin, applies `submit` and prints "ok" or "conflict:<reason>" ("missing" when
 * the subject does not exist). CLI only; never loaded by a worker.
 *
 * Usage: php apply-child.php <database url> <subject id>
 */

use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceSubject;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$manager = RaceHarness::entityManager(RaceHarness::urlParams((string) ($argv[1] ?? '')));
$subject = $manager->find(RaceSubject::class, (int) ($argv[2] ?? 0));
if (!$subject instanceof RaceSubject) {
    fwrite(\STDOUT, "missing\n");
} else {
    fwrite(\STDOUT, "ready\n");
    fgets(\STDIN);

    try {
        RaceHarness::applicator($manager)->apply($subject, RaceHarness::SLUG, 'submit');
        fwrite(\STDOUT, "ok\n");
    } catch (WorkflowConflictException $conflict) {
        fwrite(\STDOUT, 'conflict:' . $conflict->reason . "\n");
    }
}
$manager->getConnection()->close();
