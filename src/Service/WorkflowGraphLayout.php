<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use function array_key_exists;
use function count;
use function max;
use function mb_strlen;
use function min;
use function round;
use function usort;

/**
 * Layered (Sugiyama-style) layout of a branching workflow graph for the flow diagram.
 *
 * 1. Edge classification: a depth-first search from the initial place (then from every place not reached yet, in
 *    place order) marks the edges that close a cycle as back-edges ("return / loop transitions"); every other edge
 *    is a forward edge.
 * 2. Ranking: longest path over the forward edges (topological order), so every forward edge goes from a lower rank
 *    to a higher one and a place sits right of all its predecessors. A back-edge always ends on a rank lower than or
 *    equal to its source.
 * 3. Ordering: forward edges that span more than one rank get a virtual waypoint on every rank they cross; places
 *    and waypoints of each rank are ordered by barycentre sweeps (fewer crossings).
 * 4. Geometry: SVG coordinates of every place box, one connector path per edge that ends at the centre of its target
 *    place's left side, and the box of the edge label on its own connector (moved along the connector when two
 *    labels would overlap).
 *
 * Pure and stateless: safe to share between requests in a kept-alive worker.
 */
final class WorkflowGraphLayout
{
    public const NODE_WIDTH     = 168;
    public const NODE_HEIGHT    = 76;
    public const WAYPOINT_SLOT  = 28;
    public const ROW_GAP        = 24;
    public const MIN_COLUMN_GAP = 176;
    public const MAX_COLUMN_GAP = 240;
    public const LABEL_HEIGHT   = 28;
    public const PADDING        = 12;
    public const PORT_STEP      = 10;
    public const PORT_MARGIN    = 14;

    private const SWEEPS = 4;

    /** Positions along a connector tried for its label, centre first. */
    private const LABEL_POSITIONS = [0.5, 0.3, 0.7, 0.2, 0.8];

    /**
     * @param list<array<string, mixed>> $places presented places (name, sortOrder, …)
     * @param list<array<string, mixed>> $transitions presented transitions (displayLabel, fromPlaces, toPlaces)
     *
     * @return array{
     *     columns: list<array{index: int, places: list<array<string, mixed>>}>,
     *     bridges: list<list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>>,
     *     backEdges: list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>,
     *     ranks: array<string, int>,
     *     diagram: array{
     *         width: int,
     *         height: int,
     *         nodes: list<array{place: array<string, mixed>, x: float, y: float, width: int, height: int}>,
     *         edges: list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string, path: string, labelX: float, labelY: float, labelLeft: float, labelTop: float, labelWidth: int, labelHeight: int}>
     *     }
     * }
     */
    public function layout(array $places, array $transitions, string $initialPlace): array
    {
        usort(
            $places,
            static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder'] ?: strcmp((string) $a['name'], (string) $b['name']),
        );

        /** @var array<string, array<string, mixed>> $placeByName */
        $placeByName = [];
        foreach ($places as $place) {
            $placeByName[(string) $place['name']] = $place;
        }

        $edges             = $this->edges($transitions, $placeByName);
        $back              = $this->backEdges($placeByName, $edges, $initialPlace);
        $ranks             = $this->ranks($placeByName, $edges, $back);
        [$layers, $chains] = $this->layers($placeByName, $edges, $back, $ranks);
        $layers            = $this->order($layers, $chains);

        $columns = [];
        foreach ($layers as $index => $layer) {
            $columnPlaces = [];
            foreach ($layer as $node) {
                if (isset($placeByName[$node])) {
                    $columnPlaces[] = $placeByName[$node];
                }
            }
            $columns[] = ['index' => $index, 'places' => $columnPlaces];
        }

        $bridges   = array_fill(0, max(0, count($layers) - 1), []);
        $backEdges = [];
        foreach ($edges as $i => $edge) {
            if (isset($back[$i])) {
                $backEdges[] = $edge;
            } else {
                $bridges[$ranks[$edge['fromPlace']]][] = $edge;
            }
        }

        usort(
            $backEdges,
            static fn (array $a, array $b): int => [$a['fromPlace'], $a['toPlace']] <=> [$b['fromPlace'], $b['toPlace']],
        );

        return [
            'columns'   => $columns,
            'bridges'   => $bridges,
            'backEdges' => $backEdges,
            'ranks'     => $ranks,
            'diagram'   => $this->geometry($layers, $chains, $edges, $placeByName),
        ];
    }

    /**
     * One edge per (from place, to place) pair of every transition; places that do not exist are skipped.
     *
     * @param list<array<string, mixed>> $transitions
     * @param array<string, array<string, mixed>> $placeByName
     *
     * @return list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>
     */
    private function edges(array $transitions, array $placeByName): array
    {
        $edges = [];
        foreach ($transitions as $index => $transition) {
            /** @var list<string> $from */
            $from = $transition['fromPlaces'];
            /** @var list<string> $to */
            $to = $transition['toPlaces'];
            foreach ($from as $fromPlace) {
                foreach ($to as $toPlace) {
                    if (!isset($placeByName[$fromPlace], $placeByName[$toPlace])) {
                        continue;
                    }

                    $edges[] = [
                        'transitionIndex' => $index,
                        'transitionLabel' => (string) $transition['displayLabel'],
                        'fromPlace'       => $fromPlace,
                        'toPlace'         => $toPlace,
                    ];
                }
            }
        }

        return $edges;
    }

    /**
     * Depth-first search from the initial place, then from the places it did not reach: an edge to a place still on
     * the search stack (self-loops included) closes a cycle.
     *
     * @param array<string, array<string, mixed>> $placeByName
     * @param list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}> $edges
     *
     * @return array<int, true> back-edge indexes
     */
    private function backEdges(array $placeByName, array $edges, string $initialPlace): array
    {
        $outgoing = array_fill_keys(array_keys($placeByName), []);
        foreach ($edges as $i => $edge) {
            $outgoing[$edge['fromPlace']][] = $i;
        }

        $roots = array_keys($placeByName);
        if (isset($placeByName[$initialPlace])) {
            array_unshift($roots, $initialPlace);
        }

        /** @var array<string, int> $state 1 = on the stack, 2 = done */
        $state = [];
        $back  = [];
        foreach ($roots as $root) {
            if (isset($state[$root])) {
                continue;
            }

            // Iterative DFS: [place, next outgoing edge offset].
            $stack        = [[$root, 0]];
            $state[$root] = 1;
            while ($stack !== []) {
                $top             = count($stack) - 1;
                [$node, $offset] = $stack[$top];
                if (!isset($outgoing[$node][$offset])) {
                    $state[$node] = 2;
                    array_pop($stack);
                    continue;
                }

                $stack[$top][1] = $offset + 1;
                $i              = $outgoing[$node][$offset];
                $to             = $edges[$i]['toPlace'];
                if (!isset($state[$to])) {
                    $state[$to] = 1;
                    $stack[]    = [$to, 0];
                } elseif ($state[$to] === 1) {
                    $back[$i] = true;
                }
            }
        }

        return $back;
    }

    /**
     * Longest-path ranks over the forward edges (Kahn's topological order).
     *
     * @param array<string, array<string, mixed>> $placeByName
     * @param list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}> $edges
     * @param array<int, true> $back
     *
     * @return array<string, int>
     */
    private function ranks(array $placeByName, array $edges, array $back): array
    {
        $rank     = array_fill_keys(array_keys($placeByName), 0);
        $indegree = $rank;
        $outgoing = array_fill_keys(array_keys($placeByName), []);
        foreach ($edges as $i => $edge) {
            if (isset($back[$i])) {
                continue;
            }
            ++$indegree[$edge['toPlace']];
            $outgoing[$edge['fromPlace']][] = $edge['toPlace'];
        }

        $queue = [];
        foreach ($indegree as $name => $count) {
            if ($count === 0) {
                $queue[] = $name;
            }
        }

        while ($queue !== []) {
            $node = array_shift($queue);
            foreach ($outgoing[$node] as $to) {
                $rank[$to] = max($rank[$to], $rank[$node] + 1);
                if (--$indegree[$to] === 0) {
                    $queue[] = $to;
                }
            }
        }

        return $rank;
    }

    /**
     * Places per rank (in place order) plus one waypoint per rank crossed by a long forward edge.
     *
     * @param array<string, array<string, mixed>> $placeByName
     * @param list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}> $edges
     * @param array<int, true> $back
     * @param array<string, int> $ranks
     *
     * @return array{0: list<list<string>>, 1: array<int, list<string>>} layers of node ids, node chain of every forward edge
     */
    private function layers(array $placeByName, array $edges, array $back, array $ranks): array
    {
        $maxRank = $ranks === [] ? 0 : max($ranks);
        $layers  = array_fill(0, $maxRank + 1, []);
        foreach (array_keys($placeByName) as $name) {
            $layers[$ranks[$name]][] = $name;
        }

        $chains = [];
        foreach ($edges as $i => $edge) {
            if (isset($back[$i])) {
                continue;
            }

            $chain = [$edge['fromPlace']];
            for ($rank = $ranks[$edge['fromPlace']] + 1; $rank < $ranks[$edge['toPlace']]; ++$rank) {
                $waypoint        = $this->waypointId($i, $rank);
                $layers[$rank][] = $waypoint;
                $chain[]         = $waypoint;
            }
            $chain[]    = $edge['toPlace'];
            $chains[$i] = $chain;
        }

        return [$layers, $chains];
    }

    /**
     * Barycentre sweeps (down, then up) to reduce crossings; ties keep the current order.
     *
     * @param list<list<string>> $layers
     * @param array<int, list<string>> $chains
     *
     * @return list<list<string>>
     */
    private function order(array $layers, array $chains): array
    {
        $predecessors = [];
        $successors   = [];
        foreach ($chains as $chain) {
            for ($i = 1, $n = count($chain); $i < $n; ++$i) {
                $predecessors[$chain[$i]][]   = $chain[$i - 1];
                $successors[$chain[$i - 1]][] = $chain[$i];
            }
        }

        $last = count($layers) - 1;
        for ($sweep = 0; $sweep < self::SWEEPS; ++$sweep) {
            for ($rank = 1; $rank <= $last; ++$rank) {
                $layers[$rank] = $this->reorder($layers[$rank], $layers[$rank - 1], $predecessors);
            }
            for ($rank = $last - 1; $rank >= 0; --$rank) {
                $layers[$rank] = $this->reorder($layers[$rank], $layers[$rank + 1], $successors);
            }
        }

        return $layers;
    }

    /**
     * @param list<string> $layer
     * @param list<string> $reference
     * @param array<string, list<string>> $neighbours
     *
     * @return list<string>
     */
    private function reorder(array $layer, array $reference, array $neighbours): array
    {
        $position = array_flip($reference);
        $keyed    = [];
        foreach ($layer as $current => $node) {
            $sum   = 0;
            $count = 0;
            foreach ($neighbours[$node] ?? [] as $neighbour) {
                $sum += $position[$neighbour];
                ++$count;
            }
            // Barycentre in reference positions; nodes without neighbours there keep their own position.
            $keyed[] = [$count === 0 ? (float) $current : $sum / $count, $current, $node];
        }

        usort($keyed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($keyed, 2);
    }

    /**
     * @param list<list<string>> $layers
     * @param array<int, list<string>> $chains
     * @param list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}> $edges
     * @param array<string, array<string, mixed>> $placeByName
     *
     * @return array{
     *     width: int,
     *     height: int,
     *     nodes: list<array{place: array<string, mixed>, x: float, y: float, width: int, height: int}>,
     *     edges: list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string, path: string, labelX: float, labelY: float, labelLeft: float, labelTop: float, labelWidth: int, labelHeight: int}>
     * }
     */
    private function geometry(array $layers, array $chains, array $edges, array $placeByName): array
    {
        $labelWidths = [];
        $columnGap   = self::MIN_COLUMN_GAP;
        foreach ($chains as $i => $chain) {
            $labelWidths[$i] = self::labelWidth($edges[$i]['transitionLabel']);
            $columnGap       = max($columnGap, $labelWidths[$i] + 32);
        }
        $columnGap = min($columnGap, self::MAX_COLUMN_GAP);

        $layerHeights  = [];
        $contentHeight = 0;
        foreach ($layers as $rank => $layer) {
            $height = 0;
            foreach ($layer as $node) {
                $height += $this->slotHeight($node, $placeByName);
            }
            $layerHeights[$rank] = $height + max(0, count($layer) - 1) * self::ROW_GAP;
            $contentHeight       = max($contentHeight, $layerHeights[$rank]);
        }

        /** @var array<string, array{x: float, y: float, height: int}> $boxes top-left corner and height per node id */
        $boxes = [];
        $nodes = [];
        foreach ($layers as $rank => $layer) {
            $x = self::PADDING + $rank * (self::NODE_WIDTH + $columnGap);
            $y = self::PADDING + ($contentHeight - $layerHeights[$rank]) / 2;
            foreach ($layer as $node) {
                $height       = $this->slotHeight($node, $placeByName);
                $boxes[$node] = ['x' => (float) $x, 'y' => $y, 'height' => $height];
                if (isset($placeByName[$node])) {
                    $nodes[] = ['place' => $placeByName[$node], 'x' => (float) $x, 'y' => $this->round($y), 'width' => self::NODE_WIDTH, 'height' => self::NODE_HEIGHT];
                }
                $y += $height + self::ROW_GAP;
            }
        }

        $ports    = $this->ports($chains, $boxes);
        $labels   = [];
        $edgesOut = [];
        foreach ($chains as $i => $chain) {
            $source   = $boxes[$chain[0]];
            $previous = [$source['x'] + self::NODE_WIDTH, $ports[$i]];
            $path     = 'M' . $this->point($previous);
            $segment  = null;
            for ($step = 1, $last = count($chain); $step < $last; ++$step) {
                $node   = $chain[$step];
                $box    = $boxes[$node];
                $middle = $box['y'] + $box['height'] / 2;
                $entry  = [$box['x'], $middle];
                $bend   = ($previous[0] + $entry[0]) / 2;
                $path .= ' C' . $this->point([$bend, $previous[1]]) . ' ' . $this->point([$bend, $entry[1]]) . ' ' . $this->point($entry);
                $segment ??= [$previous, $entry];
                if (!isset($placeByName[$node])) {
                    $previous = [$box['x'] + self::NODE_WIDTH, $middle];
                    $path .= ' L' . $this->point($previous);
                }
            }

            // Every chain has at least its source and its target: the first segment always exists.
            [$labelX, $labelY] = $this->placeLabel($segment[0] ?? $previous, $segment[1] ?? $previous, $labelWidths[$i], $labels);
            $labels[]          = [$labelX, $labelY, $labelWidths[$i]];

            $edgesOut[] = $edges[$i] + [
                'path'        => $path,
                'labelX'      => $labelX,
                'labelY'      => $labelY,
                'labelLeft'   => $this->round($labelX - $labelWidths[$i] / 2),
                'labelTop'    => $this->round($labelY - self::LABEL_HEIGHT / 2),
                'labelWidth'  => $labelWidths[$i],
                'labelHeight' => self::LABEL_HEIGHT,
            ];
        }

        return [
            'width'  => (int) (2 * self::PADDING + count($layers) * self::NODE_WIDTH + (count($layers) - 1) * $columnGap),
            'height' => (int) ceil(2 * self::PADDING + $contentHeight),
            'nodes'  => $nodes,
            'edges'  => $edgesOut,
        ];
    }

    /**
     * Start height of every connector on the right side of its source place: the connectors leaving one place are
     * spread over its side (ordered like their next node, so they do not cross at the source); a single connector
     * leaves from the middle.
     *
     * @param array<int, list<string>> $chains
     * @param array<string, array{x: float, y: float, height: int}> $boxes
     *
     * @return array<int, float>
     */
    private function ports(array $chains, array $boxes): array
    {
        $bySource = [];
        foreach ($chains as $i => $chain) {
            $next                  = $boxes[$chain[1]];
            $bySource[$chain[0]][] = [$next['y'] + $next['height'] / 2, $i];
        }

        $ports = [];
        foreach ($bySource as $source => $leaving) {
            sort($leaving);
            $count  = count($leaving);
            $step   = $count > 1 ? min(self::PORT_STEP, (self::NODE_HEIGHT - 2 * self::PORT_MARGIN) / ($count - 1)) : 0;
            $middle = $boxes[$source]['y'] + self::NODE_HEIGHT / 2;
            foreach ($leaving as $offset => [, $i]) {
                $ports[$i] = $middle + ($offset - ($count - 1) / 2) * $step;
            }
        }

        return $ports;
    }

    /**
     * Label centre on the connector segment start → end (cubic curve with horizontal tangents): the first of
     * {@see self::LABEL_POSITIONS} whose box does not overlap a label placed before, else the middle.
     *
     * @param array{0: float, 1: float} $start
     * @param array{0: float, 1: float} $end
     * @param list<array{0: float, 1: float, 2: int}> $placed
     *
     * @return array{0: float, 1: float}
     */
    private function placeLabel(array $start, array $end, int $width, array $placed): array
    {
        $bend = ($start[0] + $end[0]) / 2;
        foreach (self::LABEL_POSITIONS as $t) {
            $u = 1 - $t;
            $x = $u ** 3 * $start[0] + 3 * $u ** 2 * $t * $bend + 3 * $u * $t ** 2 * $bend + $t ** 3 * $end[0];
            $y = $u ** 3 * $start[1] + 3 * $u ** 2 * $t * $start[1] + 3 * $u * $t ** 2 * $end[1] + $t ** 3 * $end[1];

            $free = true;
            foreach ($placed as [$otherX, $otherY, $otherWidth]) {
                if (abs($x - $otherX) < ($width + $otherWidth) / 2 + 4 && abs($y - $otherY) < self::LABEL_HEIGHT + 4) {
                    $free = false;
                    break;
                }
            }

            if ($free) {
                return [$this->round($x), $this->round($y)];
            }
        }

        return [$this->round(($start[0] + $end[0]) / 2), $this->round(($start[1] + $end[1]) / 2)];
    }

    /**
     * Estimated rendered width of a transition label pill (0.8rem text, 0.75rem side padding).
     */
    public static function labelWidth(string $label): int
    {
        return mb_strlen($label) * 7 + 28;
    }

    private function waypointId(int $edge, int $rank): string
    {
        return "\0" . $edge . ':' . $rank;
    }

    /**
     * @param array<string, array<string, mixed>> $placeByName
     */
    private function slotHeight(string $node, array $placeByName): int
    {
        return array_key_exists($node, $placeByName) ? self::NODE_HEIGHT : self::WAYPOINT_SLOT;
    }

    /**
     * @param array{0: float, 1: float} $point
     */
    private function point(array $point): string
    {
        return $this->round($point[0]) . ',' . $this->round($point[1]);
    }

    private function round(float $value): float
    {
        return round($value, 1);
    }
}
