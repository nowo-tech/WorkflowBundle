<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A transition within a persisted workflow definition.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_transition')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_transition_name', columns: ['workflow_id', 'name'])]
class WorkflowTransition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'transitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $workflow = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label;

    /** @var list<string> */
    #[ORM\Column(name: 'from_places', type: Types::JSON)]
    private array $fromPlaces;

    /** @var list<string> */
    #[ORM\Column(name: 'to_places', type: Types::JSON)]
    private array $toPlaces;

    /**
     * Creates a transition with its name, source places, target places and optional label.
     *
     * @param list<string> $fromPlaces
     * @param list<string> $toPlaces
     */
    public function __construct(string $name, array $fromPlaces, array $toPlaces, ?string $label = null)
    {
        $this->name       = $name;
        $this->fromPlaces = array_values($fromPlaces);
        $this->toPlaces   = array_values($toPlaces);
        $this->label      = $label;
    }

    /**
     * Returns the database identifier (null before the first flush).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the owning workflow definition.
     */
    public function getWorkflow(): ?WorkflowDefinition
    {
        return $this->workflow;
    }

    /**
     * Sets the owning workflow definition.
     */
    public function setWorkflow(?WorkflowDefinition $workflow): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->workflow = $workflow;

        return $this;
    }

    /**
     * Returns the technical transition name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the technical transition name.
     */
    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this;
    }

    /**
     * Returns the optional display label.
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * Sets the optional display label.
     */
    public function setLabel(?string $label): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->label = $label;

        return $this;
    }

    /**
     * Returns the source place names.
     *
     * @return list<string>
     */
    public function getFromPlaces(): array
    {
        return $this->fromPlaces;
    }

    /**
     * Sets the source place names.
     *
     * @param list<string> $fromPlaces
     */
    public function setFromPlaces(array $fromPlaces): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->fromPlaces = array_values($fromPlaces);

        return $this;
    }

    /**
     * Returns the target place names.
     *
     * @return list<string>
     */
    public function getToPlaces(): array
    {
        return $this->toPlaces;
    }

    /**
     * Sets the target place names.
     *
     * @param list<string> $toPlaces
     */
    public function setToPlaces(array $toPlaces): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->toPlaces = array_values($toPlaces);

        return $this;
    }

    /**
     * Returns the label, or the name when no label is set.
     */
    public function getDisplayLabel(): string
    {
        return $this->label ?? $this->name;
    }
}
