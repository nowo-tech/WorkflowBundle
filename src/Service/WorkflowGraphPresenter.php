<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;

use function count;
use function in_array;

/**
 * Builds graph data for the workflow detail visualization.
 *
 * Layout: a single chain of places renders as a linear `sequence`; any other graph goes through
 * {@see WorkflowGraphLayout} (ranks from the initial place, `columns` per rank, forward `bridges` per source rank,
 * true `backEdges` only, and the SVG `diagram` geometry).
 */
final class WorkflowGraphPresenter
{
    /**
     * @return array{
     *     initialPlace: string,
     *     type: string,
     *     typeLabel: string,
     *     places: list<array{
     *         id: int|null,
     *         name: string,
     *         label: string|null,
     *         displayLabel: string,
     *         sortOrder: int,
     *         index: int,
     *         isInitial: bool,
     *         incoming: list<array{transitionIndex: int, transitionName: string, transitionLabel: string, fromPlaces: list<string>}>,
     *         outgoing: list<array{transitionIndex: int, transitionName: string, transitionLabel: string, toPlaces: list<string>}>
     *     }>,
     *     transitions: list<array{
     *         id: int|null,
     *         name: string,
     *         label: string|null,
     *         displayLabel: string,
     *         fromPlaces: list<string>,
     *         toPlaces: list<string>
     *     }>,
     *     layout: array{
     *         sequence: list<array<string, mixed>>|null,
     *         columns: list<array{index: int, places: list<array<string, mixed>>}>,
     *         bridges: list<list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>>,
     *         backEdges: list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>,
     *         ranks: array<string, int>,
     *         diagram: array<string, mixed>|null
     *     }
     * }
     */
    public function present(WorkflowDefinition $definition): array
    {
        $transitions = [];
        foreach ($definition->getTransitions() as $transition) {
            $transitions[] = $this->presentTransition($transition);
        }

        $places     = [];
        $placeIndex = 0;
        foreach ($definition->getPlaces() as $place) {
            $places[] = $this->presentPlace($place, $definition, $transitions, $placeIndex);
            ++$placeIndex;
        }

        $placeByName = [];
        foreach ($places as $place) {
            $placeByName[$place['name']] = $place;
        }

        return [
            'initialPlace' => $definition->getInitialPlace(),
            'type'         => $definition->getType()->value,
            'typeLabel'    => $definition->getType()->label(),
            'places'       => $places,
            'transitions'  => $transitions,
            'layout'       => $this->buildLayout($places, $transitions, $definition->getInitialPlace(), $placeByName),
        ];
    }

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     * @param array<string, array<string, mixed>> $placeByName
     *
     * @return array{
     *     sequence: list<array<string, mixed>>|null,
     *     columns: list<array{index: int, places: list<array<string, mixed>>}>,
     *     bridges: list<list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>>,
     *     backEdges: list<array{transitionIndex: int, transitionLabel: string, fromPlace: string, toPlace: string}>,
     *     ranks: array<string, int>,
     *     diagram: array<string, mixed>|null
     * }
     */
    private function buildLayout(array $places, array $transitions, string $initialPlace, array $placeByName): array
    {
        $sequence = $this->buildLinearSequence($places, $transitions, $initialPlace, $placeByName);
        if ($sequence !== null) {
            return [
                'sequence'  => $sequence,
                'columns'   => [],
                'bridges'   => [],
                'backEdges' => [],
                'ranks'     => [],
                'diagram'   => null,
            ];
        }

        return ['sequence' => null] + (new WorkflowGraphLayout())->layout($places, $transitions, $initialPlace);
    }

    /**
     * @param list<array<string, mixed>> $places
     * @param list<array<string, mixed>> $transitions
     * @param array<string, array<string, mixed>> $placeByName
     *
     * @return list<array<string, mixed>>|null
     */
    private function buildLinearSequence(array $places, array $transitions, string $initialPlace, array $placeByName): ?array
    {
        if ($places === [] || !isset($placeByName[$initialPlace])) {
            return null;
        }

        $incomingCount = array_fill_keys(array_keys($placeByName), 0);
        $outgoingCount = array_fill_keys(array_keys($placeByName), 0);

        foreach ($transitions as $transition) {
            foreach ($transition['fromPlaces'] as $from) {
                if (isset($outgoingCount[$from])) {
                    ++$outgoingCount[$from];
                }
            }

            foreach ($transition['toPlaces'] as $to) {
                if (isset($incomingCount[$to])) {
                    ++$incomingCount[$to];
                }
            }
        }

        foreach (array_keys($placeByName) as $name) {
            if ($incomingCount[$name] > 1 || $outgoingCount[$name] > 1) {
                return null;
            }
        }

        $sequence = [];
        $visited  = [];
        $current  = $initialPlace;

        while ($current !== null) {
            if (isset($visited[$current])) {
                return null;
            }

            $visited[$current] = true;
            $sequence[]        = ['type' => 'place', 'place' => $placeByName[$current]];

            $nextTransition = null;
            foreach ($transitions as $index => $transition) {
                if (in_array($current, $transition['fromPlaces'], true)) {
                    $nextTransition = ['index' => $index, 'data' => $transition];
                    break;
                }
            }

            if ($nextTransition === null) {
                break;
            }

            if (count($nextTransition['data']['toPlaces']) !== 1) {
                return null;
            }

            $sequence[] = [
                'type'            => 'transition',
                'transitionIndex' => $nextTransition['index'],
                'transitionLabel' => $nextTransition['data']['displayLabel'],
            ];

            $current = $nextTransition['data']['toPlaces'][0];
        }

        return count($visited) === count($places) ? $sequence : null;
    }

    /**
     * @param list<array{
     *     id: int|null,
     *     name: string,
     *     label: string|null,
     *     displayLabel: string,
     *     fromPlaces: list<string>,
     *     toPlaces: list<string>
     * }> $transitions
     *
     * @return array{
     *     id: int|null,
     *     name: string,
     *     label: string|null,
     *     displayLabel: string,
     *     sortOrder: int,
     *     index: int,
     *     isInitial: bool,
     *     incoming: list<array{transitionIndex: int, transitionName: string, transitionLabel: string, fromPlaces: list<string>}>,
     *     outgoing: list<array{transitionIndex: int, transitionName: string, transitionLabel: string, toPlaces: list<string>}>
     * }
     */
    private function presentPlace(WorkflowPlace $place, WorkflowDefinition $definition, array $transitions, int $placeIndex): array
    {
        $name     = $place->getName();
        $incoming = [];
        $outgoing = [];

        foreach ($transitions as $transitionIndex => $transition) {
            if (in_array($name, $transition['toPlaces'], true)) {
                $incoming[] = [
                    'transitionIndex' => $transitionIndex,
                    'transitionName'  => $transition['name'],
                    'transitionLabel' => $transition['displayLabel'],
                    'fromPlaces'      => $transition['fromPlaces'],
                ];
            }

            if (in_array($name, $transition['fromPlaces'], true)) {
                $outgoing[] = [
                    'transitionIndex' => $transitionIndex,
                    'transitionName'  => $transition['name'],
                    'transitionLabel' => $transition['displayLabel'],
                    'toPlaces'        => $transition['toPlaces'],
                ];
            }
        }

        return [
            'id'           => $place->getId(),
            'name'         => $name,
            'label'        => $place->getLabel(),
            'displayLabel' => $place->getDisplayLabel(),
            'sortOrder'    => $place->getSortOrder(),
            'index'        => $placeIndex,
            'isInitial'    => $name === $definition->getInitialPlace(),
            'incoming'     => $incoming,
            'outgoing'     => $outgoing,
        ];
    }

    /** @return array{id: int|null, name: string, label: string|null, displayLabel: string, fromPlaces: list<string>, toPlaces: list<string>} */
    private function presentTransition(WorkflowTransition $transition): array
    {
        return [
            'id'           => $transition->getId(),
            'name'         => $transition->getName(),
            'label'        => $transition->getLabel(),
            'displayLabel' => $transition->getDisplayLabel(),
            'fromPlaces'   => $transition->getFromPlaces(),
            'toPlaces'     => $transition->getToPlaces(),
        ];
    }
}
