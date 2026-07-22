<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
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

    public function testCachesWorkflowInstances(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addTransition(new WorkflowTransition('noop', ['draft'], ['draft']));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::once())->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $registry->get('order_approval');
        $registry->get('order_approval');
    }

    public function testHasReturnsFalseForMissingSlug(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        self::assertFalse($registry->has('missing'));
    }

    public function testHasReturnsTrueForExistingSlug(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addTransition(new WorkflowTransition('noop', ['draft'], ['draft']));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        self::assertTrue($registry->has('order_approval'));
    }

    public function testInvalidateClearsCache(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $registry->get('order_approval');
        $registry->invalidate('order_approval');
        $registry->get('order_approval');
    }

    public function testGetThrowsWhenDefinitionDisabled(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->setEnabled(false);

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($definition);

        $this->expectException(WorkflowNotFoundException::class);
        (new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()))->get('order_approval');
    }

    public function testGetThrowsWhenDefinitionMissing(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);

        $this->expectException(WorkflowNotFoundException::class);
        (new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()))->get('missing');
    }

    public function testInvalidateWithoutSlugClearsAllCachedWorkflows(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $registry->get('order_approval');
        $registry->invalidate();
        $registry->get('order_approval');
    }

    public function testCreateWorkflowIsPublic(): void
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addTransition(new WorkflowTransition('noop', ['draft'], ['draft']));

        $registry = new DatabaseWorkflowRegistry(
            $this->createMock(WorkflowDefinitionRepository::class),
            new WorkflowDefinitionBuilder(),
        );

        self::assertSame('order_approval', $registry->createWorkflow($definition)->getName());
    }
}
