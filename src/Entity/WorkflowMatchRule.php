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
    protected ?int $id = null;

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
    private int $sortOrder;

    /**
     * Creates a match rule for a context parameter key / value pair.
     */
    public function __construct(string $parameterKey, string $parameterValue, int $sortOrder = 0)
    {
        $this->parameterKey   = $parameterKey;
        $this->parameterValue = $parameterValue;
        $this->sortOrder      = $sortOrder;
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
     * Returns the context parameter key to match.
     */
    public function getParameterKey(): string
    {
        return $this->parameterKey;
    }

    /**
     * Sets the context parameter key to match.
     */
    public function setParameterKey(string $parameterKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parameterKey = $parameterKey;

        return $this;
    }

    /**
     * Returns the expected context parameter value.
     */
    public function getParameterValue(): string
    {
        return $this->parameterValue;
    }

    /**
     * Sets the expected context parameter value.
     */
    public function setParameterValue(string $parameterValue): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parameterValue = $parameterValue;

        return $this;
    }

    /**
     * Returns the display order of the rule.
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * Sets the display order of the rule.
     */
    public function setSortOrder(int $sortOrder): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * Returns the free-form rule metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Replaces the free-form rule metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->metadata = $metadata;

        return $this;
    }
}
