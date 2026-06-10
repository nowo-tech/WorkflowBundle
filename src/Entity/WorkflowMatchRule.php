<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Key/value rule used to select a workflow definition at runtime.
 *
 * All rules of a definition must match (AND). Use multiple rules for multi-parameter lookup.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_match_rule')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_match_rule', columns: ['workflow_id', 'parameter_key'])]
class WorkflowMatchRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'matchRules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $workflow = null;

    #[ORM\Column(name: 'parameter_key', type: Types::STRING, length: 128)]
    private string $parameterKey;

    #[ORM\Column(name: 'parameter_value', type: Types::STRING, length: 255)]
    private string $parameterValue;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 0;

    public function __construct(string $parameterKey, string $parameterValue, int $sortOrder = 0)
    {
        $this->parameterKey   = $parameterKey;
        $this->parameterValue = $parameterValue;
        $this->sortOrder      = $sortOrder;
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

    public function getParameterKey(): string
    {
        return $this->parameterKey;
    }

    public function setParameterKey(string $parameterKey): self
    {
        $this->parameterKey = $parameterKey;

        return $this;
    }

    public function getParameterValue(): string
    {
        return $this->parameterValue;
    }

    public function setParameterValue(string $parameterValue): self
    {
        $this->parameterValue = $parameterValue;

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

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
