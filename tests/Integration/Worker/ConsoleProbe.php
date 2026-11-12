<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Worker;

use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;

/**
 * What the test commands share with the test (no static state).
 */
final class ConsoleProbe
{
    public ?bool $kept = null;

    public function __construct(public readonly WorkflowEntityManager $manager)
    {
    }
}
