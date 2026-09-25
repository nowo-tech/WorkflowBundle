<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Symfony\Component\Workflow\Metadata\MetadataStoreInterface;
use Symfony\Component\Workflow\Transition;

use function is_string;

/**
 * Exposes persisted workflow metadata to Symfony Workflow.
 *
 * Metadata is copied at construction time so the store never keeps a reference to a Doctrine entity.
 */
final class DatabaseMetadataStore implements MetadataStoreInterface
{
    /** @var array<string, mixed> */
    private readonly array $workflowMetadata;

    /** @var array<string, string> */
    private readonly array $placeLabels;

    /** @var array<string, string> */
    private readonly array $transitionLabels;

    public function __construct(WorkflowDefinition $definition)
    {
        $this->workflowMetadata = [
            'name' => $definition->getName(),
            'slug' => $definition->getSlug(),
            'type' => $definition->getType()->value,
        ];

        $placeLabels = [];
        foreach ($definition->getPlaces() as $workflowPlace) {
            $placeLabels[$workflowPlace->getName()] ??= $workflowPlace->getDisplayLabel();
        }
        $this->placeLabels = $placeLabels;

        $transitionLabels = [];
        foreach ($definition->getTransitions() as $workflowTransition) {
            $transitionLabels[$workflowTransition->getName()] ??= $workflowTransition->getDisplayLabel();
        }
        $this->transitionLabels = $transitionLabels;
    }

    /** @return array<string, mixed> */
    public function getWorkflowMetadata(): array
    {
        return $this->workflowMetadata;
    }

    /** @return array<string, mixed> */
    public function getPlaceMetadata(string $place): array
    {
        if (!isset($this->placeLabels[$place])) {
            return [];
        }

        return ['label' => $this->placeLabels[$place]];
    }

    /** @return array<string, mixed> */
    public function getTransitionMetadata(Transition $transition): array
    {
        if (!isset($this->transitionLabels[$transition->getName()])) {
            return [];
        }

        return ['label' => $this->transitionLabels[$transition->getName()]];
    }

    public function getMetadata(string $key, string|Transition|null $subject = null): mixed
    {
        if ($subject === null) {
            return $this->getWorkflowMetadata()[$key] ?? null;
        }

        if (is_string($subject)) {
            return $this->getPlaceMetadata($subject)[$key] ?? null;
        }

        return $this->getTransitionMetadata($subject)[$key] ?? null;
    }
}
