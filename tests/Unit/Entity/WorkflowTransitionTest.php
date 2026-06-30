<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use PHPUnit\Framework\TestCase;

final class WorkflowTransitionTest extends TestCase
{
    public function testDisplayLabelAndReindexesPlaces(): void
    {
        $transition = new WorkflowTransition('approve', ['draft'], ['approved'], 'Approve');

        self::assertSame('Approve', $transition->getDisplayLabel());
        self::assertSame(['draft'], $transition->getFromPlaces());

        $transition->setFromPlaces(['a' => 'draft', 1 => 'review']); // @phpstan-ignore argument.type
        $transition->setToPlaces(['x' => 'done']); // @phpstan-ignore argument.type

        self::assertSame(['draft', 'review'], $transition->getFromPlaces());
        self::assertSame(['done'], $transition->getToPlaces());
        self::assertSame('approve', $transition->getName());
    }
}
