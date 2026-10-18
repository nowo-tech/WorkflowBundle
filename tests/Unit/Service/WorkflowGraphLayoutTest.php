<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowGraphLayout;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function count;
use function sprintf;

final class WorkflowGraphLayoutTest extends TestCase
{
    /** @var array<string, WorkflowDefinition> */
    private array $demo = [];

    public function testChangeRequestReviewRanksFromTheInitialPlace(): void
    {
        $layout = $this->layoutOf($this->demo('change_request_review'));

        self::assertSame(
            ['draft' => 0, 'review' => 1, 'changes_needed' => 2, 'approved' => 2, 'rejected' => 2, 'cancelled' => 2],
            $layout['ranks'],
        );
        self::assertSame(['draft'], array_column($layout['columns'][0]['places'], 'name'));
        self::assertSame(['review'], array_column($layout['columns'][1]['places'], 'name'), 'Cancelled is not ranked next to Review');
        self::assertCount(4, $layout['columns'][2]['places']);
    }

    public function testChangeRequestReviewListsOnlyTrueBackEdgesAsLoops(): void
    {
        $layout = $this->layoutOf($this->demo('change_request_review'));

        self::assertSame(
            [['changes_needed', 'Resubmit', 'review'], ['rejected', 'Reopen', 'draft']],
            array_map(static fn (array $edge): array => [$edge['fromPlace'], $edge['transitionLabel'], $edge['toPlace']], $layout['backEdges']),
        );

        $forward = [];
        foreach ($layout['bridges'] as $rank => $edges) {
            foreach ($edges as $edge) {
                $forward[] = $rank . ':' . $edge['fromPlace'] . '>' . $edge['toPlace'];
            }
        }
        sort($forward);
        self::assertSame(
            ['0:draft>cancelled', '0:draft>review', '1:review>approved', '1:review>cancelled', '1:review>changes_needed', '1:review>rejected'],
            $forward,
            'review → cancel → cancelled is a forward edge',
        );
    }

    public function testChangeRequestReviewConnectorsEndAtTheirOwnTarget(): void
    {
        $layout  = $this->layoutOf($this->demo('change_request_review'));
        $diagram = $layout['diagram'];

        $expected = [
            'Submit for review' => ['draft', 'review'],
            'Approve'           => ['review', 'approved'],
            'Reject'            => ['review', 'rejected'],
            'Request changes'   => ['review', 'changes_needed'],
        ];
        foreach ($diagram['edges'] as $edge) {
            if (isset($expected[$edge['transitionLabel']])) {
                self::assertSame($expected[$edge['transitionLabel']], [$edge['fromPlace'], $edge['toPlace']]);
            }
        }
        self::assertCount(6, $diagram['edges']);
        self::assertSame(['Cancel', 'Cancel'], array_values(array_filter(array_column($diagram['edges'], 'transitionLabel'), static fn (string $label): bool => $label === 'Cancel')));

        // draft → cancelled crosses rank 1 through a waypoint below Review (straight run, then the last curve).
        $long = array_values(array_filter($diagram['edges'], static fn (array $edge): bool => $edge['fromPlace'] === 'draft' && $edge['toPlace'] === 'cancelled'))[0];
        self::assertStringContainsString(' L', $long['path']);
        $review = $this->node($diagram, 'review');
        self::assertGreaterThan($review['y'] + $review['height'], $this->waypointY($long['path']), 'the long edge passes below Review, not through it');
    }

    /**
     * Invariants on every seeded demo definition that is not a single chain.
     */
    #[DataProvider('demoSlugs')]
    public function testDemoDefinitionsHaveConsistentLayouts(string $slug): void
    {
        $graph  = (new WorkflowGraphPresenter())->present($this->demo($slug));
        $layout = $graph['layout'];

        if ($layout['sequence'] !== null) {
            self::assertNull($layout['diagram']);
            self::assertCount(count($graph['places']), array_filter($layout['sequence'], static fn (array $item): bool => $item['type'] === 'place'));

            return;
        }

        self::assertNotNull($layout['diagram']);
        $ranks = $layout['ranks'];
        self::assertSame(0, $ranks['draft'], 'the initial place starts the diagram');

        foreach ($layout['bridges'] as $edges) {
            foreach ($edges as $edge) {
                self::assertGreaterThan($ranks[$edge['fromPlace']], $ranks[$edge['toPlace']], sprintf('%s → %s is forward', $edge['fromPlace'], $edge['toPlace']));
            }
        }
        foreach ($layout['backEdges'] as $edge) {
            self::assertLessThanOrEqual($ranks[$edge['fromPlace']], $ranks[$edge['toPlace']], sprintf('%s → %s is a loop', $edge['fromPlace'], $edge['toPlace']));
        }

        $this->assertConnectorsAndLabels($layout['diagram']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function demoSlugs(): iterable
    {
        foreach (['order_approval_default', 'change_request_review', 'invoice_processing', 'contract_review', 'acme_finance_expense', 'acme_hr_expense', 'globex_expense', 'acme_eu_high_po', 'acme_eu_po', 'default_purchase_order'] as $slug) {
            yield $slug => [$slug];
        }
    }

    public function testStandardApprovalFlowLoopsBackFromRejected(): void
    {
        $layout = $this->layoutOf($this->demo('order_approval_default'));

        self::assertSame(['draft' => 0, 'review' => 1, 'approved' => 2, 'rejected' => 2], $layout['ranks']);
        self::assertSame([['rejected', 'draft']], array_map(static fn (array $edge): array => [$edge['fromPlace'], $edge['toPlace']], $layout['backEdges']));
    }

    public function testSelfLoopsAndUnreachablePlaces(): void
    {
        $definition = new WorkflowDefinition('Loops', 'loops', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        foreach (['draft', 'done', 'archived', 'orphan'] as $i => $name) {
            $definition->addPlace(new WorkflowPlace($name, null, $i));
        }
        $definition->addTransition(new WorkflowTransition('finish', ['draft'], ['done'], 'Finish'));
        $definition->addTransition(new WorkflowTransition('touch', ['done'], ['done'], 'Touch'));
        $definition->addTransition(new WorkflowTransition('archive', ['done'], ['archived'], 'Archive'));
        $definition->addTransition(new WorkflowTransition('ghost', ['done'], ['missing'], 'Ghost'));

        $layout = $this->layoutOf($definition);

        self::assertSame(['draft' => 0, 'done' => 1, 'archived' => 2, 'orphan' => 0], $layout['ranks']);
        $names = array_map(static fn (WorkflowTransition $transition): string => $transition->getName(), $definition->getTransitions()->getValues());
        self::assertSame(['touch'], array_map(static fn (array $edge): string => $names[$edge['transitionIndex']], $layout['backEdges']));
        self::assertSame(['draft', 'orphan'], array_column($layout['columns'][0]['places'], 'name'));
        self::assertCount(2, $layout['diagram']['edges'], 'the self-loop is listed, the edge to an unknown place is dropped');
    }

    public function testWorkflowSplitAndJoinAndInitialPlaceOutsideThePlaces(): void
    {
        $definition = new WorkflowDefinition('Split', 'split', 'start', 'App\\Entity\\X', WorkflowType::Workflow);
        foreach (['draft', 'legal', 'tech', 'published'] as $i => $name) {
            $definition->addPlace(new WorkflowPlace($name, null, $i));
        }
        $definition->addTransition(new WorkflowTransition('review', ['draft'], ['legal', 'tech'], 'Review'));
        $definition->addTransition(new WorkflowTransition('publish', ['legal', 'tech'], ['published'], 'Publish'));

        $layout = $this->layoutOf($definition);

        self::assertSame(['draft' => 0, 'legal' => 1, 'tech' => 1, 'published' => 2], $layout['ranks']);
        self::assertSame([], $layout['backEdges']);
        self::assertCount(4, $layout['diagram']['edges'], 'one connector per (from, to) pair');
        $this->assertConnectorsAndLabels($layout['diagram']);
    }

    public function testParallelTransitionsFallBackToTheMiddleWhenEveryLabelSlotIsTaken(): void
    {
        $definition = new WorkflowDefinition('Parallel', 'parallel', 'draft', 'App\\Entity\\X', WorkflowType::StateMachine);
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('done', null, 1));
        for ($i = 0; $i < 7; ++$i) {
            $definition->addTransition(new WorkflowTransition('go' . $i, ['draft'], ['done'], 'Go ' . $i));
        }

        $edges = $this->layoutOf($definition)['diagram']['edges'];

        self::assertCount(7, $edges);
        $last  = $edges[6];
        $start = $this->point('/^M([\d.]+),([\d.]+)/', $last['path']);
        self::assertEqualsWithDelta(($start[0] + $this->targetX($last['path'])) / 2, $last['labelX'], 0.11, 'no free slot left: the label sits in the middle of its connector');
        self::assertEqualsWithDelta(($start[1] + $this->targetY($last['path'])) / 2, $last['labelY'], 0.11);
    }

    public function testEmptyDefinition(): void
    {
        $layout = (new WorkflowGraphLayout())->layout([], [], 'draft');

        self::assertSame([['index' => 0, 'places' => []]], $layout['columns']);
        self::assertSame([], $layout['bridges']);
        self::assertSame([], $layout['diagram']['nodes']);
        self::assertSame(2 * WorkflowGraphLayout::PADDING + WorkflowGraphLayout::NODE_WIDTH, $layout['diagram']['width']);
    }

    public function testLabelWidthGrowsWithTheLabel(): void
    {
        self::assertSame(28, WorkflowGraphLayout::labelWidth(''));
        self::assertSame(28 + 7 * 7, WorkflowGraphLayout::labelWidth('Approve'));
    }

    /**
     * Every connector starts on the right side of its source and ends at the middle of its target's left side; its
     * label box lies between the two and overlaps no other label.
     *
     * @param array<string, mixed> $diagram
     */
    private function assertConnectorsAndLabels(array $diagram): void
    {
        foreach ($diagram['edges'] as $i => $edge) {
            $source = $this->node($diagram, $edge['fromPlace']);
            $target = $this->node($diagram, $edge['toPlace']);

            $start = $this->point('/^M([\d.]+),([\d.]+)/', $edge['path']);
            self::assertEqualsWithDelta($source['x'] + $source['width'], $start[0], 0.01);
            self::assertGreaterThanOrEqual($source['y'], $start[1]);
            self::assertLessThanOrEqual($source['y'] + $source['height'], $start[1]);

            self::assertEqualsWithDelta($target['x'], $this->targetX($edge['path']), 0.01, sprintf('%s → %s ends on its target', $edge['fromPlace'], $edge['toPlace']));
            self::assertEqualsWithDelta($target['y'] + $target['height'] / 2, $this->targetY($edge['path']), 0.11, sprintf('%s → %s ends at the middle of its target', $edge['fromPlace'], $edge['toPlace']));

            self::assertGreaterThan($source['x'] + $source['width'], $edge['labelX']);
            self::assertLessThan($target['x'], $edge['labelX']);

            foreach ($diagram['edges'] as $j => $other) {
                if ($j <= $i) {
                    continue;
                }
                $overlap = abs($edge['labelX'] - $other['labelX']) < ($edge['labelWidth'] + $other['labelWidth']) / 2
                    && abs($edge['labelY'] - $other['labelY']) < WorkflowGraphLayout::LABEL_HEIGHT;
                self::assertFalse($overlap, sprintf('labels "%s" and "%s" overlap', $edge['transitionLabel'], $other['transitionLabel']));
            }
        }
    }

    /**
     * @param array<string, mixed> $diagram
     *
     * @return array{x: float, y: float, width: int, height: int}
     */
    private function node(array $diagram, string $name): array
    {
        foreach ($diagram['nodes'] as $node) {
            if ($node['place']['name'] === $name) {
                return $node;
            }
        }

        self::fail('No node ' . $name);
    }

    private function targetX(string $path): float
    {
        return $this->point('/([\d.]+),([\d.]+)$/', $path)[0];
    }

    private function targetY(string $path): float
    {
        return $this->point('/([\d.]+),([\d.]+)$/', $path)[1];
    }

    private function waypointY(string $path): float
    {
        return $this->point('/ L([\d.]+),([\d.]+)/', $path)[1];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function point(string $pattern, string $path): array
    {
        self::assertSame(1, preg_match($pattern, $path, $match), $path);

        return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutOf(WorkflowDefinition $definition): array
    {
        $layout = (new WorkflowGraphPresenter())->present($definition)['layout'];
        self::assertNull($layout['sequence']);

        return $layout;
    }

    /**
     * Definitions exactly as {@see DemoSeedService} seeds them for the demo.
     */
    private function demo(string $slug): WorkflowDefinition
    {
        if ($this->demo === []) {
            $repository = $this->createMock(WorkflowDefinitionRepository::class);
            $repository->method('findOneBySlug')->willReturn(null);
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->method('persist')->willReturnCallback(function (object $definition): void {
                self::assertInstanceOf(WorkflowDefinition::class, $definition);
                $this->demo[$definition->getSlug()] = $definition;
            });

            (new DemoSeedService($entityManager, $repository, new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder())))->seed();
        }

        self::assertArrayHasKey($slug, $this->demo);

        return $this->demo[$slug];
    }
}
