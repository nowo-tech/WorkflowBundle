<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use PHPUnit\Framework\TestCase;

final class WorkflowPlaceTest extends TestCase
{
    public function testDisplayLabelAndSetters(): void
    {
        $place = new WorkflowPlace('draft', 'Draft label', 2);

        self::assertSame('Draft label', $place->getDisplayLabel());
        self::assertSame(2, $place->getSortOrder());

        $definition = new WorkflowDefinition('W', 'w', 'draft', 'App\\Entity\\X');
        $place->setName('submitted')->setLabel(null)->setSortOrder(1)->setWorkflow($definition);

        self::assertSame('submitted', $place->getName());
        self::assertSame('submitted', $place->getDisplayLabel());
        self::assertSame($definition, $place->getWorkflow());
    }
}
