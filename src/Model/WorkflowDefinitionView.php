<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use DateTimeImmutable;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowDefinitionStatus;
use Nowo\WorkflowBundle\Enum\WorkflowType;

use function array_map;
use function array_values;

/**
 * Framework-free, read-only snapshot of one stored definition version (since 1.19, REQ-ARCH-001 rule 1): what
 * {@see WorkflowVersionManagerInterface} returns instead of the {@see WorkflowDefinition} aggregate.
 *
 * Places and transitions are plain name lists, never a Doctrine `Collection`: the full shape (labels, guards, match
 * rules) stays the admin UI's and the bundle's own business. The snapshot is taken when the view is built and is
 * never refreshed; call the manager again for the current state.
 */
final readonly class WorkflowDefinitionView
{
    /**
     * @param list<string> $placeNames place names in sort order
     * @param list<string> $transitionNames transition names in sort order
     * @param array<string, mixed> $metadata definition metadata
     */
    public function __construct(
        public WorkflowDefinitionRef $ref,
        public string $name,
        public WorkflowType $type,
        public WorkflowDefinitionStatus $status,
        public string $initialPlace,
        public string $subjectClass,
        public string $markingProperty,
        public bool $enabled,
        public ?string $description,
        public int $priority,
        public ?int $templateVersion,
        public array $placeNames,
        public array $transitionNames,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * The view of a stored definition.
     */
    public static function fromDefinition(WorkflowDefinition $definition): self
    {
        return new self(
            WorkflowDefinitionRef::fromDefinition($definition),
            $definition->getName(),
            $definition->getType(),
            $definition->getStatus(),
            $definition->getInitialPlace(),
            $definition->getSubjectClass(),
            $definition->getMarkingProperty(),
            $definition->isEnabled(),
            $definition->getDescription(),
            $definition->getPriority(),
            $definition->getTemplateVersion(),
            $definition->getPlaceNames(),
            array_values(array_map(
                static fn (WorkflowTransition $transition): string => $transition->getName(),
                $definition->getTransitions()->toArray(),
            )),
            $definition->getMetadata(),
            $definition->getCreatedAt(),
            $definition->getUpdatedAt(),
        );
    }

    /**
     * Whether this version is the open draft of its scope (edited, never runs).
     */
    public function isDraft(): bool
    {
        return $this->status === WorkflowDefinitionStatus::Draft;
    }

    /**
     * Whether new subjects can start with this version.
     */
    public function isPublished(): bool
    {
        return $this->status === WorkflowDefinitionStatus::Published;
    }

    /**
     * Whether this version only keeps the subjects already pinned to it running.
     */
    public function isRetired(): bool
    {
        return $this->status === WorkflowDefinitionStatus::Retired;
    }
}
