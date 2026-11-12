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
    /** Workflow metadata key: id of the stored definition version (since 1.15). */
    public const DEFINITION_ID = 'nowo_definition_id';

    /** Workflow metadata key: version number of the stored definition (since 1.15). */
    public const DEFINITION_VERSION = 'nowo_definition_version';

    /** Workflow metadata key: tenant key of the stored definition ('' = global template), since 1.15. */
    public const DEFINITION_TENANT = 'nowo_definition_tenant';

    /** Transition metadata key: permission guard (since 1.15). */
    public const GUARD_PERMISSION = 'nowo_guard_permission';

    /** Transition metadata key: condition guard (since 1.15). */
    public const GUARD_EXPRESSION = 'nowo_guard_expression';

    /** Transition metadata key: automatic transition (since 1.15). */
    public const AUTOMATIC = 'nowo_automatic';

    /** @var array<string, mixed> */
    private readonly array $workflowMetadata;

    /** @var array<string, string> */
    private readonly array $placeLabels;

    /** @var array<string, string> */
    private readonly array $transitionLabels;

    /** @var array<string, array<string, bool|string>> guard settings of the transitions that have some (since 1.15) */
    private readonly array $transitionGuards;

    /**
     * Copies the workflow, place and transition labels out of the definition entity.
     */
    public function __construct(WorkflowDefinition $definition)
    {
        $this->workflowMetadata = [
            'name' => $definition->getName(),
            'slug' => $definition->getSlug(),
            'type' => $definition->getType()->value,
            // Since 1.15: which stored version runs (tenant isolation, version pinning, audit).
            self::DEFINITION_ID      => $definition->getId(),
            self::DEFINITION_VERSION => $definition->getVersion(),
            self::DEFINITION_TENANT  => $definition->getTenantKey(),
        ];

        $placeLabels = [];
        foreach ($definition->getPlaces() as $workflowPlace) {
            $placeLabels[$workflowPlace->getName()] ??= $workflowPlace->getDisplayLabel();
        }
        $this->placeLabels = $placeLabels;

        $transitionLabels = [];
        $transitionGuards = [];
        foreach ($definition->getTransitions() as $workflowTransition) {
            $name = $workflowTransition->getName();
            $transitionLabels[$name] ??= $workflowTransition->getDisplayLabel();
            $guards = array_filter([
                self::GUARD_PERMISSION => $workflowTransition->getGuardPermission(),
                self::GUARD_EXPRESSION => $workflowTransition->getGuardExpression(),
                self::AUTOMATIC        => $workflowTransition->isAutomatic() ?: null,
            ], static fn (bool|string|null $value): bool => $value !== null);
            if ($guards !== [] && !isset($transitionGuards[$name])) {
                $transitionGuards[$name] = $guards;
            }
        }
        $this->transitionLabels = $transitionLabels;
        $this->transitionGuards = $transitionGuards;
    }

    /**
     * Returns the workflow metadata (name, slug, type).
     *
     * @return array<string, mixed>
     */
    public function getWorkflowMetadata(): array
    {
        return $this->workflowMetadata;
    }

    /**
     * Returns the metadata (label) of a place.
     *
     * @return array<string, mixed>
     */
    public function getPlaceMetadata(string $place): array
    {
        if (!isset($this->placeLabels[$place])) {
            return [];
        }

        return ['label' => $this->placeLabels[$place]];
    }

    /**
     * Returns the metadata (label) of a transition.
     *
     * @return array<string, mixed>
     */
    public function getTransitionMetadata(Transition $transition): array
    {
        if (!isset($this->transitionLabels[$transition->getName()])) {
            return [];
        }

        return ['label' => $this->transitionLabels[$transition->getName()]] + ($this->transitionGuards[$transition->getName()] ?? []);
    }

    /**
     * Returns one metadata value of the workflow, a place or a transition.
     */
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
