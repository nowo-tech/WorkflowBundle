<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use function in_array;

/**
 * A subject's marking in a database workflow (since 1.15): the definition version that ran and the places.
 */
final readonly class WorkflowMarkingView
{
    /**
     * @param list<string> $places
     * @param string|null $definitionTenant tenant key of the definition version (null = global template)
     */
    public function __construct(
        public string $workflowSlug,
        public int $definitionVersion,
        public ?string $definitionTenant,
        public array $places,
    ) {
    }

    public function has(string $place): bool
    {
        return in_array($place, $this->places, true);
    }

    /**
     * The single place of a state machine ('' when the marking is empty), like WorkflowApplicator::getMarking().
     */
    public function place(): string
    {
        return $this->places[0] ?? '';
    }
}
