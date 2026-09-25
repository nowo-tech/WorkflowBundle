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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $this->requestStackWith(new Request()));
        $first    = $registry->get('order_approval');

        self::assertSame($first, $registry->get('order_approval'));
    }

    public function testDoesNotMemoizeWithoutMainRequest(): void
    {
        $definition = $this->definitionWithTransition('approve');

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), new RequestStack());
        $registry->get('order_approval');
        $registry->get('order_approval');
    }

    public function testDoesNotMemoizeWithoutRequestStack(): void
    {
        $definition = $this->definitionWithTransition('approve');

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->willReturn($definition);

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());
        $registry->get('order_approval');
        $registry->get('order_approval');
    }

    public function testSecondRequestWithoutResetSeesDefinitionChangedByAnotherWorker(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturnOnConsecutiveCalls(
            $this->definitionWithTransition('approve'),
            $this->definitionWithTransition('reject'),
        );
        $requestStack = new RequestStack([new Request()]);
        $registry     = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $requestStack);

        self::assertSame('approve', $registry->get('order_approval')->getDefinition()->getTransitions()[0]->getName());
        $requestStack->pop();

        $requestStack->push(new Request());
        self::assertSame('reject', $registry->get('order_approval')->getDefinition()->getTransitions()[0]->getName());
        $requestStack->pop();
    }

    public function testSecondRequestWithoutResetSeesDefinitionDisabledByAnotherWorker(): void
    {
        $disabled = $this->definitionWithTransition('approve');
        $disabled->setEnabled(false);

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturnOnConsecutiveCalls(
            $this->definitionWithTransition('approve'),
            $disabled,
        );
        $requestStack = new RequestStack([new Request()]);
        $registry     = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $requestStack);

        self::assertTrue($registry->has('order_approval'));
        $requestStack->pop();

        $requestStack->push(new Request());
        self::assertFalse($registry->has('order_approval'));
    }

    public function testSubRequestReusesMainRequestMemo(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::once())->method('findOneBySlug')->willReturn($this->definitionWithTransition('approve'));

        $requestStack = $this->requestStackWith(new Request());
        $registry     = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $requestStack);

        $first = $registry->get('order_approval');
        $requestStack->push(new Request());

        self::assertSame($first, $registry->get('order_approval'));
    }

    public function testResetClearsMemo(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBySlug')->willReturn($this->definitionWithTransition('approve'));

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $this->requestStackWith(new Request()));
        $registry->get('order_approval');
        $registry->reset();
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

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $this->requestStackWith(new Request()));
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

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder(), $this->requestStackWith(new Request()));
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

    private function definitionWithTransition(string $transition): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', 'App\\Entity\\DemoOrder');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('done', null, 1));
        $definition->addTransition(new WorkflowTransition($transition, ['draft'], ['done']));

        return $definition;
    }

    private function requestStackWith(Request $request): RequestStack
    {
        return new RequestStack([$request]);
    }
}
