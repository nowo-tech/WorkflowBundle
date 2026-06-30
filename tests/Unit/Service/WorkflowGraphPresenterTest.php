<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function count;

final class WorkflowGraphPresenterTest extends TestCase
{
    public function testBuildsIncomingAndOutgoingTransitionsPerPlace(): void
    {
        $definition = new WorkflowDefinition('Test', 'test', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('approved', 'Approved', 1));
        $definition->addTransition(new WorkflowTransition('approve', ['draft'], ['approved'], 'Approve'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertSame('draft', $graph['initialPlace']);
        self::assertCount(2, $graph['places']);
        self::assertTrue($graph['places'][0]['isInitial']);
        self::assertSame([], $graph['places'][0]['incoming']);
        self::assertCount(1, $graph['places'][0]['outgoing']);
        self::assertSame('approve', $graph['places'][0]['outgoing'][0]['transitionName']);
        self::assertCount(1, $graph['places'][1]['incoming']);
        self::assertSame([], $graph['places'][1]['outgoing']);
    }

    public function testBuildsLinearSequenceForSimpleFlows(): void
    {
        $definition = new WorkflowDefinition('Globex', 'globex_expense', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('approved', 'Approved', 1));
        $definition->addTransition(new WorkflowTransition('auto_approve', ['draft'], ['approved'], 'Auto approve'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNotNull($graph['layout']['sequence']);
        self::assertSame('place', $graph['layout']['sequence'][0]['type']);
        self::assertSame('draft', $graph['layout']['sequence'][0]['place']['name']);
        self::assertSame('transition', $graph['layout']['sequence'][1]['type']);
        self::assertSame('Auto approve', $graph['layout']['sequence'][1]['transitionLabel']);
        self::assertSame('place', $graph['layout']['sequence'][2]['type']);
        self::assertSame('approved', $graph['layout']['sequence'][2]['place']['name']);
    }

    public function testBuildsColumnLayoutForBranchingFlows(): void
    {
        $definition = new WorkflowDefinition('Approval', 'approval', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('review', 'Review', 1));
        $definition->addPlace(new WorkflowPlace('approved', 'Approved', 2));
        $definition->addPlace(new WorkflowPlace('rejected', 'Rejected', 3));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit'));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
        $definition->addTransition(new WorkflowTransition('reject', ['review'], ['rejected'], 'Reject'));
        $definition->addTransition(new WorkflowTransition('reopen', ['rejected'], ['draft'], 'Reopen'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNull($graph['layout']['sequence']);
        self::assertCount(3, $graph['layout']['columns']);
        self::assertCount(2, $graph['layout']['bridges'][1]);
        self::assertCount(1, $graph['layout']['backEdges']);
        self::assertSame('Reopen', $graph['layout']['backEdges'][0]['transitionLabel']);
    }

    public function testBuildsColumnLayoutForChangeRequestWorkflow(): void
    {
        $definition = new WorkflowDefinition('Change request', 'change_request_review', 'draft', 'App\\Entity\\DemoChangeRequest', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('review', 'Review', 1));
        $definition->addPlace(new WorkflowPlace('changes_needed', 'Changes needed', 2));
        $definition->addPlace(new WorkflowPlace('approved', 'Approved', 3));
        $definition->addPlace(new WorkflowPlace('rejected', 'Rejected', 4));
        $definition->addPlace(new WorkflowPlace('cancelled', 'Cancelled', 5));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit'));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
        $definition->addTransition(new WorkflowTransition('reject', ['review'], ['rejected'], 'Reject'));
        $definition->addTransition(new WorkflowTransition('request_changes', ['review'], ['changes_needed'], 'Request changes'));
        $definition->addTransition(new WorkflowTransition('resubmit', ['changes_needed'], ['review'], 'Resubmit'));
        $definition->addTransition(new WorkflowTransition('reopen', ['rejected'], ['draft'], 'Reopen'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNull($graph['layout']['sequence']);
        self::assertGreaterThanOrEqual(3, count($graph['layout']['columns']));
        self::assertGreaterThanOrEqual(2, count($graph['layout']['bridges'][1]));
        self::assertGreaterThanOrEqual(2, count($graph['layout']['backEdges']));
    }

    public function testAssignColumnsTerminatesForCyclicWorkflows(): void
    {
        $definition = new WorkflowDefinition('Change request', 'change_request_review', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('review', 'Review', 1));
        $definition->addPlace(new WorkflowPlace('rejected', 'Rejected', 2));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit'));
        $definition->addTransition(new WorkflowTransition('reject', ['review'], ['rejected'], 'Reject'));
        $definition->addTransition(new WorkflowTransition('reopen', ['rejected'], ['draft'], 'Reopen'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertSame(0, $graph['layout']['columns'][0]['places'][0]['index']);
        self::assertNotEmpty($graph['layout']['backEdges']);
    }

    public function testPresentReturnsEmptyLayoutForDefinitionWithoutPlaces(): void
    {
        $definition = new WorkflowDefinition('Empty', 'empty', 'missing', 'App\\Entity\\X', WorkflowType::StateMachine);

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertSame('missing', $graph['initialPlace']);
        self::assertSame([], $graph['places']);
        self::assertNull($graph['layout']['sequence']);
        self::assertCount(1, $graph['layout']['columns']);
        self::assertSame([], $graph['layout']['columns'][0]['places']);
    }

    public function testLinearSequenceReturnsNullWhenInitialPlaceMissing(): void
    {
        $definition = new WorkflowDefinition('Broken', 'broken', 'missing', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNull($graph['layout']['sequence']);
    }

    public function testLinearSequenceReturnsNullForBranchingOutgoing(): void
    {
        $definition = new WorkflowDefinition('Branch', 'branch', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('a', 'A', 1));
        $definition->addPlace(new WorkflowPlace('b', 'B', 2));
        $definition->addTransition(new WorkflowTransition('split', ['draft'], ['a', 'b'], 'Split'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNull($graph['layout']['sequence']);
    }

    public function testBuildEdgeGroupsIgnoresUnknownPlaces(): void
    {
        $definition = new WorkflowDefinition('Ghost', 'ghost', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addTransition(new WorkflowTransition('jump', ['ghost'], ['draft'], 'Jump'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertCount(1, $graph['places']);
        self::assertSame([], $graph['layout']['backEdges']);
    }

    public function testBuildEdgeGroupsIgnoresPartiallyUnknownPlacesInBranchingFlow(): void
    {
        $definition = new WorkflowDefinition('Branch', 'branch', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addPlace(new WorkflowPlace('review', 'Review', 1));
        $definition->addPlace(new WorkflowPlace('approved', 'Approved', 2));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit'));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
        $definition->addTransition(new WorkflowTransition('external', ['review'], ['missing'], 'External'));

        $graph = (new WorkflowGraphPresenter())->present($definition);

        self::assertNull($graph['layout']['sequence']);
        self::assertSame([], $graph['layout']['backEdges']);
    }

    public function testBuildEdgeGroupsSkipsEdgesWithUnknownColumns(): void
    {
        $presenter = new WorkflowGraphPresenter();
        $method    = new ReflectionMethod(WorkflowGraphPresenter::class, 'buildEdgeGroups');
        $method->setAccessible(true);

        [$bridges, $backEdges] = $method->invoke($presenter, [
            [
                'displayLabel' => 'External',
                'fromPlaces'   => ['review'],
                'toPlaces'     => ['missing'],
            ],
        ], ['review' => 1]);

        self::assertSame([], $bridges[0] ?? []);
        self::assertSame([], $backEdges);
    }
}
