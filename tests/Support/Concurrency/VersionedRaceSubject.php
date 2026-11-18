<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host subject entity with a Doctrine #[Version] column (optimistic concurrency mode).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfrt_versioned_race_subject')]
class VersionedRaceSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(type: 'string', length: 64)]
    private string $note = '';

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(string $status = 'draft')
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
