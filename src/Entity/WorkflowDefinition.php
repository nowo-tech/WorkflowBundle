<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

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
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: WorkflowType::class)]
    private WorkflowType $type = WorkflowType::StateMachine;

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

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $metadata = [];

    /** @var Collection<int, WorkflowMatchRule> */
    #[ORM\OneToMany(
        targetEntity: WorkflowMatchRule::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'parameterKey' => 'ASC'])]
    private Collection $matchRules;

    /** @var Collection<int, WorkflowPlace> */
    #[ORM\OneToMany(
        targetEntity: WorkflowPlace::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $places;

    /** @var Collection<int, WorkflowTransition> */
    #[ORM\OneToMany(
        targetEntity: WorkflowTransition::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $transitions;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

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
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this->touch();
    }

    public function getType(): WorkflowType
    {
        return $this->type;
    }

    public function setType(WorkflowType $type): self
    {
        $this->type = $type;

        return $this->touch();
    }

    public function getInitialPlace(): string
    {
        return $this->initialPlace;
    }

    public function setInitialPlace(string $initialPlace): self
    {
        $this->initialPlace = $initialPlace;

        return $this->touch();
    }

    public function getSubjectClass(): string
    {
        return $this->subjectClass;
    }

    public function setSubjectClass(string $subjectClass): self
    {
        $this->subjectClass = $subjectClass;

        return $this->touch();
    }

    public function getMarkingProperty(): string
    {
        return $this->markingProperty;
    }

    public function setMarkingProperty(string $markingProperty): self
    {
        $this->markingProperty = $markingProperty;

        return $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this->touch();
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this->touch();
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

        return $this->touch();
    }

    /** @return Collection<int, WorkflowMatchRule> */
    public function getMatchRules(): Collection
    {
        return $this->matchRules;
    }

    public function addMatchRule(WorkflowMatchRule $rule): self
    {
        if (!$this->matchRules->contains($rule)) {
            $this->matchRules->add($rule);
            $rule->setWorkflow($this);
        }

        return $this->touch();
    }

    public function removeMatchRule(WorkflowMatchRule $rule): self
    {
        if ($this->matchRules->removeElement($rule) && $rule->getWorkflow() === $this) {
            $rule->setWorkflow(null);
        }

        return $this->touch();
    }

    public function isDefaultMatcher(): bool
    {
        return $this->matchRules->isEmpty();
    }

    /** @return array<string, string> */
    public function getMatchParameters(): array
    {
        $parameters = [];
        foreach ($this->matchRules as $rule) {
            $parameters[$rule->getParameterKey()] = $rule->getParameterValue();
        }

        ksort($parameters);

        return $parameters;
    }

    public function matchesContext(WorkflowContext $context): bool
    {
        if ($context->subjectClass !== null && $this->subjectClass !== $context->subjectClass) {
            return false;
        }

        foreach ($this->matchRules as $rule) {
            if ($context->get($rule->getParameterKey()) !== $rule->getParameterValue()) {
                return false;
            }
        }

        return true;
    }

    public function getMatchSpecificity(): int
    {
        return $this->matchRules->count();
    }

    /** @return Collection<int, WorkflowPlace> */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    public function addPlace(WorkflowPlace $place): self
    {
        if (!$this->places->contains($place)) {
            $this->places->add($place);
            $place->setWorkflow($this);
        }

        return $this->touch();
    }

    public function removePlace(WorkflowPlace $place): self
    {
        if ($this->places->removeElement($place) && $place->getWorkflow() === $this) {
            $place->setWorkflow(null);
        }

        return $this->touch();
    }

    /** @return Collection<int, WorkflowTransition> */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }

    public function addTransition(WorkflowTransition $transition): self
    {
        if (!$this->transitions->contains($transition)) {
            $this->transitions->add($transition);
            $transition->setWorkflow($this);
        }

        return $this->touch();
    }

    public function removeTransition(WorkflowTransition $transition): self
    {
        if ($this->transitions->removeElement($transition) && $transition->getWorkflow() === $this) {
            $transition->setWorkflow(null);
        }

        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> */
    public function getPlaceNames(): array
    {
        return array_values(array_map(
            static fn (WorkflowPlace $place): string => $place->getName(),
            $this->places->toArray(),
        ));
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
