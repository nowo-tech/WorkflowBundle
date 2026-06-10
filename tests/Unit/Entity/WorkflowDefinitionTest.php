<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    public function testStoresPlacesAndTransitions(): void
    {
        $definition = new WorkflowDefinition('Test', 'test', 'a', 'App\\Entity\\X', WorkflowType::StateMachine);
        $place      = new WorkflowPlace('a', 'Place A', 0);
        $transition = new WorkflowTransition('go', ['a'], ['b']);

        $definition->addPlace($place);
        $definition->addTransition($transition);

        self::assertSame(['a'], $definition->getPlaceNames());
        self::assertSame($definition, $place->getWorkflow());
        self::assertSame($definition, $transition->getWorkflow());
    }
}
