<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function trim;

/**
 * A transition within a persisted workflow definition.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_transition')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_transition_name', columns: ['workflow_id', 'name'])]
class WorkflowTransition
{
    use LabelTranslationTrait;

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

    /** Since 1.15: only an actor with this permission in the subject's context may apply it (host port). */
    #[ORM\Column(name: 'guard_permission', type: Types::STRING, length: 128, nullable: true)]
    private ?string $guardPermission = null;

    /** Since 1.15: condition on the whitelisted subject variables, e.g. `days <= 2`. */
    #[ORM\Column(name: 'guard_expression', type: Types::TEXT, nullable: true)]
    private ?string $guardExpression = null;

    /** Since 1.15: applied automatically by WorkflowApplicator as soon as it is enabled (and its guards pass). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $automatic = false;

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

    /**
     * The permission an actor needs in the subject's context to apply this transition (null = none), since 1.15.
     */
    public function getGuardPermission(): ?string
    {
        return $this->guardPermission;
    }

    public function setGuardPermission(?string $guardPermission): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->guardPermission = $guardPermission === '' ? null : $guardPermission;

        return $this;
    }

    /**
     * The condition on the subject variables (null = none), since 1.15.
     */
    public function getGuardExpression(): ?string
    {
        return $this->guardExpression;
    }

    public function setGuardExpression(?string $guardExpression): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->guardExpression = $guardExpression === null || trim($guardExpression) === '' ? null : $guardExpression;

        return $this;
    }

    /**
     * Whether WorkflowApplicator applies this transition automatically once enabled, since 1.15.
     */
    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    public function setAutomatic(bool $automatic): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->automatic = $automatic;

        return $this;
    }
}
