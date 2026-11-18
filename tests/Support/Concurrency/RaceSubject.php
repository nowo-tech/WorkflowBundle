<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host subject entity whose marking is the `status` column (REQ-RUNTIME-003 race tests).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfrt_race_subject')]
class RaceSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $status;

    #[ORM\Column(type: 'string', length: 64)]
    private string $note = '';

    /** Not mapped: a marking the database does not hold (no concurrency control possible). */
    private string $phase = 'draft';

    public function __construct(?string $status = 'draft')
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }
}
