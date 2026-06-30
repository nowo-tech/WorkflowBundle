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
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'transitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $workflow = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    /** @var list<string> */
    #[ORM\Column(name: 'from_places', type: Types::JSON)]
    private array $fromPlaces = [];

    /** @var list<string> */
    #[ORM\Column(name: 'to_places', type: Types::JSON)]
    private array $toPlaces = [];

    /**
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): ?WorkflowDefinition
    {
        return $this->workflow;
    }

    public function setWorkflow(?WorkflowDefinition $workflow): self
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @return list<string> */
    public function getFromPlaces(): array
    {
        return $this->fromPlaces;
    }

    /** @param list<string> $fromPlaces */
    public function setFromPlaces(array $fromPlaces): self
    {
        $this->fromPlaces = array_values($fromPlaces);

        return $this;
    }

    /** @return list<string> */
    public function getToPlaces(): array
    {
        return $this->toPlaces;
    }

    /** @param list<string> $toPlaces */
    public function setToPlaces(array $toPlaces): self
    {
        $this->toPlaces = array_values($toPlaces);

        return $this;
    }

    public function getDisplayLabel(): string
    {
        return $this->label ?? $this->name;
    }
}
