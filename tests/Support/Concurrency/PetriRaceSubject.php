<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host subject of a Petri-net workflow (multiple state): its marking is a JSON place => tokens map.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfrt_petri_race_subject')]
class PetriRaceSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /**
     * @param array<string, int>|null $marking
     */
    public function __construct(
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $marking = ['start' => 1],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return array<string, int>|null
     */
    public function getMarking(): ?array
    {
        return $this->marking;
    }

    /**
     * @param array<string, int>|null $marking
     * @param array<string, mixed> $context
     */
    public function setMarking(?array $marking, array $context = []): void
    {
        $this->marking = $marking;
    }
}
