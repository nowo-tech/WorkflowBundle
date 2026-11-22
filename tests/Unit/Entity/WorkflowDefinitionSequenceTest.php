<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use Nowo\WorkflowBundle\Entity\WorkflowDefinitionSequence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionSequenceTest extends TestCase
{
    #[Test]
    public function aCounterIsKeyedByTenantAndSlugAndStartsAtZero(): void
    {
        $counter = new WorkflowDefinitionSequence('org-a', 'leave');
        self::assertSame('org-a', $counter->getTenantKey());
        self::assertSame('leave', $counter->getSlug());
        self::assertSame(0, $counter->getLastVersion());
        self::assertSame(3, (new WorkflowDefinitionSequence('', 'leave', 3))->getLastVersion());
    }
}
