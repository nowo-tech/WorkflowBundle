<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Symfony\Component\Workflow\Metadata\MetadataStoreInterface;
use Symfony\Component\Workflow\Transition;

use function is_string;

/**
 * Exposes persisted workflow metadata to Symfony Workflow.
 */
final class DatabaseMetadataStore implements MetadataStoreInterface
{
    public function __construct(
        private readonly WorkflowDefinition $definition,
    ) {
    }

    /** @return array<string, mixed> */
    public function getWorkflowMetadata(): array
    {
        return [
            'name' => $this->definition->getName(),
            'slug' => $this->definition->getSlug(),
            'type' => $this->definition->getType()->value,
        ];
    }

    /** @return array<string, mixed> */
    public function getPlaceMetadata(string $place): array
    {
        foreach ($this->definition->getPlaces() as $workflowPlace) {
            if ($workflowPlace->getName() === $place) {
                return [
                    'label' => $workflowPlace->getDisplayLabel(),
                ];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function getTransitionMetadata(Transition $transition): array
    {
        foreach ($this->definition->getTransitions() as $workflowTransition) {
            if ($workflowTransition->getName() === $transition->getName()) {
                return [
                    'label' => $workflowTransition->getDisplayLabel(),
                ];
            }
        }

        return [];
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
