<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Model\WorkflowContext;
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

    public function testMatchesContextAndMatchParameters(): void
    {
        $definition = new WorkflowDefinition('PO', 'po', 'draft', 'App\\Entity\\Order');
        $definition->addMatchRule(new WorkflowMatchRule('tenant', 'acme', 0));

        self::assertFalse($definition->isDefaultMatcher());
        self::assertSame(['tenant' => 'acme'], $definition->getMatchParameters());
        self::assertSame(1, $definition->getMatchSpecificity());

        self::assertTrue($definition->matchesContext(new WorkflowContext('App\\Entity\\Order', ['tenant' => 'acme'])));
        self::assertFalse($definition->matchesContext(new WorkflowContext('App\\Entity\\Other', ['tenant' => 'acme'])));
        self::assertFalse($definition->matchesContext(new WorkflowContext('App\\Entity\\Order', ['tenant' => 'other'])));
    }

    public function testDefaultMatcherWhenNoRules(): void
    {
        $definition = new WorkflowDefinition('Default', 'default', 'draft', 'App\\Entity\\Order');

        self::assertTrue($definition->isDefaultMatcher());
        self::assertTrue($definition->matchesContext(new WorkflowContext('App\\Entity\\Order', [])));
    }

    public function testRemoveRelationsAndTouchUpdatedAt(): void
    {
        $definition = new WorkflowDefinition('Test', 'test', 'a', 'App\\Entity\\X');
        $rule       = new WorkflowMatchRule('k', 'v');
        $place      = new WorkflowPlace('a');
        $transition = new WorkflowTransition('t', ['a'], ['b']);

        $definition->addMatchRule($rule)->addPlace($place)->addTransition($transition);

        $definition->removeMatchRule($rule)->removePlace($place)->removeTransition($transition);

        self::assertNull($rule->getWorkflow());
        self::assertNull($place->getWorkflow());
        self::assertNull($transition->getWorkflow());
    }

    public function testSettersUpdateState(): void
    {
        $definition = new WorkflowDefinition('Test', 'test', 'a', 'App\\Entity\\X');

        $definition->setName('Renamed')->setEnabled(false)->setMetadata(['k' => 'v']);

        self::assertSame('Renamed', $definition->getName());
        self::assertFalse($definition->isEnabled());
        self::assertSame(['k' => 'v'], $definition->getMetadata());
        self::assertInstanceOf(DateTimeImmutable::class, $definition->getUpdatedAt());
    }

    public function testGettersSettersAndDuplicateAdds(): void
    {
        $definition = new WorkflowDefinition('Test', 'test-slug', 'a', 'App\\Entity\\X');
        $rule       = new WorkflowMatchRule('k', 'v');
        $place      = new WorkflowPlace('a');
        $transition = new WorkflowTransition('t', ['a'], ['b']);

        self::assertNull($definition->getId());
        self::assertSame('test-slug', $definition->getSlug());
        self::assertSame(WorkflowType::StateMachine, $definition->getType());
        self::assertSame('a', $definition->getInitialPlace());
        self::assertSame('App\\Entity\\X', $definition->getSubjectClass());
        self::assertSame('status', $definition->getMarkingProperty());
        self::assertNull($definition->getDescription());
        self::assertSame(0, $definition->getPriority());
        self::assertInstanceOf(DateTimeImmutable::class, $definition->getCreatedAt());

        $definition
            ->setSlug('renamed')
            ->setType(WorkflowType::Workflow)
            ->setInitialPlace('b')
            ->setSubjectClass('App\\Entity\\Y')
            ->setMarkingProperty('state')
            ->setDescription('desc')
            ->setPriority(5);

        self::assertSame('renamed', $definition->getSlug());
        self::assertSame(WorkflowType::Workflow, $definition->getType());
        self::assertSame('desc', $definition->getDescription());
        self::assertSame(5, $definition->getPriority());

        $definition->addMatchRule($rule)->addMatchRule($rule);
        $definition->addPlace($place)->addPlace($place);
        $definition->addTransition($transition)->addTransition($transition);

        self::assertCount(1, $definition->getMatchRules());
        self::assertCount(1, $definition->getPlaces());
        self::assertCount(1, $definition->getTransitions());
    }
}
