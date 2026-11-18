<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host subject whose marking column is a backed enum (`enumType`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfrt_enum_race_subject')]
class EnumRaceSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'string', length: 32, enumType: RaceStatus::class)]
    private RaceStatus $status = RaceStatus::Draft;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status->value;
    }

    public function setStatus(string $status): void
    {
        $this->status = RaceStatus::from($status);
    }
}
