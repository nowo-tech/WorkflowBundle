<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A place (state) within a persisted workflow definition.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_place')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_place_name', columns: ['workflow_id', 'name'])]
class WorkflowPlace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'places')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $workflow = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 0;

    public function __construct(string $name, ?string $label = null, int $sortOrder = 0)
    {
        $this->name      = $name;
        $this->label     = $label;
        $this->sortOrder = $sortOrder;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getDisplayLabel(): string
    {
        return $this->label ?? $this->name;
    }
}
