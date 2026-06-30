<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;

final class WorkflowDefinitionBuilderTest extends TestCase
{
    public function testBuildsDefinitionFromEntity(): void
    {
        $entity = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $entity->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $entity->addPlace(new WorkflowPlace('done', 'Done', 1));
        $entity->addTransition(new WorkflowTransition('finish', ['draft'], ['done'], 'Finish'));

        $definition = (new WorkflowDefinitionBuilder())->build($entity);

        self::assertInstanceOf(Definition::class, $definition);
        self::assertSame(['draft', 'done'], array_values($definition->getPlaces()));
        self::assertCount(1, $definition->getTransitions());
        self::assertSame('finish', $definition->getTransitions()[0]->getName());
    }

    public function testThrowsWhenNoPlaces(): void
    {
        $entity = new WorkflowDefinition('Empty', 'empty', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);

        $this->expectException(InvalidArgumentException::class);
        (new WorkflowDefinitionBuilder())->build($entity);
    }
}
