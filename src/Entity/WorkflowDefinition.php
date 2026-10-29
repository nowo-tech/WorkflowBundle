<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Util\SubjectClass;
use SortDirection;

/**
 * Persisted Symfony Workflow definition (places, transitions, subject binding).
 */
#[ORM\Entity(repositoryClass: WorkflowDefinitionRepository::class)]
#[ORM\Table(name: 'workflow_definition')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_definition_slug', columns: ['slug'])]
class WorkflowDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: WorkflowType::class)]
    private WorkflowType $type;

    #[ORM\Column(name: 'initial_place', type: Types::STRING, length: 128)]
    private string $initialPlace;

    #[ORM\Column(name: 'subject_class', type: Types::STRING, length: 255)]
    private string $subjectClass;

    #[ORM\Column(name: 'marking_property', type: Types::STRING, length: 128)]
    private string $markingProperty = 'status';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    /**
     * No database default: MySQL rejects literal defaults on JSON columns (error 1101); the PHP default applies.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    /** @var Collection<int, WorkflowMatchRule> */
    #[ORM\OneToMany(
        targetEntity: WorkflowMatchRule::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => SortDirection::Ascending, 'parameterKey' => SortDirection::Ascending])]
    private Collection $matchRules;

    /** @var Collection<int, WorkflowPlace> */
    #[ORM\OneToMany(
        targetEntity: WorkflowPlace::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => SortDirection::Ascending, 'name' => SortDirection::Ascending])]
    private Collection $places;

    /** @var Collection<int, WorkflowTransition> */
    #[ORM\OneToMany(
        targetEntity: WorkflowTransition::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['name' => SortDirection::Ascending])]
    private Collection $transitions;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * Creates a definition with its name, slug, initial place, subject class and type.
     */
    public function __construct(
        string $name,
        string $slug,
        string $initialPlace,
        string $subjectClass,
        WorkflowType $type = WorkflowType::StateMachine,
    ) {
        $this->name         = $name;
        $this->slug         = $slug;
        $this->initialPlace = $initialPlace;
        $this->subjectClass = $subjectClass;
        $this->type         = $type;
        $this->places       = new ArrayCollection();
        $this->transitions  = new ArrayCollection();
        $this->matchRules   = new ArrayCollection();
        $this->createdAt    = new DateTimeImmutable();
        $this->updatedAt    = new DateTimeImmutable();
    }

    /**
     * Returns the database identifier (null before the first flush).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the human-readable name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the human-readable name.
     */
    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this->touch();
    }

    /**
     * Returns the unique slug used to look the workflow up.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Sets the unique slug used to look the workflow up.
     */
    public function setSlug(string $slug): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->slug = $slug;

        return $this->touch();
    }

    /**
     * Returns the workflow type (workflow or state machine).
     */
    public function getType(): WorkflowType
    {
        return $this->type;
    }

    /**
     * Sets the workflow type (workflow or state machine).
     */
    public function setType(WorkflowType $type): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->type = $type;

        return $this->touch();
    }

    /**
     * Returns the initial place name.
     */
    public function getInitialPlace(): string
    {
        return $this->initialPlace;
    }

    /**
     * Sets the initial place name.
     */
    public function setInitialPlace(string $initialPlace): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->initialPlace = $initialPlace;

        return $this->touch();
    }

    /**
     * Returns the FQCN of the subjects this workflow applies to.
     */
    public function getSubjectClass(): string
    {
        return $this->subjectClass;
    }

    /**
     * Sets the FQCN of the subjects this workflow applies to.
     */
    public function setSubjectClass(string $subjectClass): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->subjectClass = $subjectClass;

        return $this->touch();
    }

    /**
     * Returns the subject property that stores the marking.
     */
    public function getMarkingProperty(): string
    {
        return $this->markingProperty;
    }

    /**
     * Sets the subject property that stores the marking.
     */
    public function setMarkingProperty(string $markingProperty): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->markingProperty = $markingProperty;

        return $this->touch();
    }

    /**
     * Tells whether the definition can be resolved at runtime.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables or disables runtime resolution of the definition.
     */
    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->enabled = $enabled;

        return $this->touch();
    }

    /**
     * Returns the optional description.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Sets the optional description.
     */
    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;

        return $this->touch();
    }

    /**
     * Returns the priority used to break ties between equally specific matches.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Sets the priority used to break ties between equally specific matches.
     */
    public function setPriority(int $priority): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->priority = $priority;

        return $this->touch();
    }

    /**
     * Returns the free-form workflow metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Replaces the free-form workflow metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->metadata = $metadata;

        return $this->touch();
    }

    /**
     * Returns the match rules (all must match the lookup context).
     *
     * @return Collection<int, WorkflowMatchRule>
     */
    public function getMatchRules(): Collection
    {
        return $this->matchRules;
    }

    /**
     * Adds a match rule and links it to this definition.
     */
    public function addMatchRule(WorkflowMatchRule $rule): self
    {
        if (!$this->matchRules->contains($rule)) {
            $this->matchRules->add($rule);
            $rule->setWorkflow($this);
        }

        return $this->touch();
    }

    /**
     * Removes a match rule and unlinks it from this definition.
     */
    public function removeMatchRule(WorkflowMatchRule $rule): self
    {
        if ($this->matchRules->removeElement($rule) && $rule->getWorkflow() === $this) {
            $rule->setWorkflow(null);
        }

        return $this->touch();
    }

    /**
     * Tells whether the definition has no match rules (default for its subject class).
     */
    public function isDefaultMatcher(): bool
    {
        return $this->matchRules->isEmpty();
    }

    /**
     * Returns the match rules as a key-sorted parameter map.
     *
     * @return array<string, string>
     */
    public function getMatchParameters(): array
    {
        $parameters = [];
        foreach ($this->matchRules as $rule) {
            $parameters[$rule->getParameterKey()] = $rule->getParameterValue();
        }

        ksort($parameters);

        return $parameters;
    }

    /**
     * Tells whether the subject class and every match rule match the given context.
     */
    public function matchesContext(WorkflowContext $context): bool
    {
        if ($context->subjectClass !== null && !SubjectClass::matches($context->subjectClass, $this->subjectClass)) {
            return false;
        }

        foreach ($this->matchRules as $rule) {
            if ($context->get($rule->getParameterKey()) !== $rule->getParameterValue()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the number of match rules (more rules = more specific).
     */
    public function getMatchSpecificity(): int
    {
        return $this->matchRules->count();
    }

    /**
     * Returns the places of the workflow.
     *
     * @return Collection<int, WorkflowPlace>
     */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    /**
     * Adds a place and links it to this definition.
     */
    public function addPlace(WorkflowPlace $place): self
    {
        if (!$this->places->contains($place)) {
            $this->places->add($place);
            $place->setWorkflow($this);
        }

        return $this->touch();
    }

    /**
     * Removes a place and unlinks it from this definition.
     */
    public function removePlace(WorkflowPlace $place): self
    {
        if ($this->places->removeElement($place) && $place->getWorkflow() === $this) {
            $place->setWorkflow(null);
        }

        return $this->touch();
    }

    /**
     * Returns the transitions of the workflow.
     *
     * @return Collection<int, WorkflowTransition>
     */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }

    /**
     * Adds a transition and links it to this definition.
     */
    public function addTransition(WorkflowTransition $transition): self
    {
        if (!$this->transitions->contains($transition)) {
            $this->transitions->add($transition);
            $transition->setWorkflow($this);
        }

        return $this->touch();
    }

    /**
     * Removes a transition and unlinks it from this definition.
     */
    public function removeTransition(WorkflowTransition $transition): self
    {
        if ($this->transitions->removeElement($transition) && $transition->getWorkflow() === $this) {
            $transition->setWorkflow(null);
        }

        return $this->touch();
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last modification timestamp.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the place names in sort order.
     *
     * @return list<string>
     */
    public function getPlaceNames(): array
    {
        return array_values(array_map(
            static fn (WorkflowPlace $place): string => $place->getName(),
            $this->places->toArray(),
        ));
    }

    private function touch(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
