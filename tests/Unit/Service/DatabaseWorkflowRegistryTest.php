<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\StateMachine;

final class DatabaseWorkflowRegistryTest extends TestCase
{
    public function testCreatesStateMachineFromDatabaseDefinition(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('approved', null, 1));
        $definition->addTransition(new WorkflowTransition('approve', ['draft'], ['approved']));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->with('order_approval')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $workflow = $registry->get('order_approval');

        self::assertInstanceOf(StateMachine::class, $workflow);
        self::assertSame('order_approval', $workflow->getName());
    }

    public function testCreatesWorkflowTypeWhenConfigured(): void
    {
        $definition = new WorkflowDefinition(
            'Doc',
            'document_review',
            'draft',
            'App\\Entity\\DemoDocument',
            WorkflowType::Workflow,
        );
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('published', null, 1));
        $definition->addTransition(new WorkflowTransition('publish', ['draft'], ['published']));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $workflow = $registry->get('document_review');

        self::assertSame('document_review', $workflow->getName());
        self::assertNotInstanceOf(StateMachine::class, $workflow);
    }
}
