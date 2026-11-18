<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

/**
 * A JOINED-inheritance root with a subclass: the marking column lives on the root table, and a query on the root class
 * left-joins the subclass table (PostgreSQL refuses FOR UPDATE on such a query).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfrt_inherited_race_subject')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['root' => InheritedRaceSubject::class, 'child' => InheritedRaceSubjectChild::class])]
class InheritedRaceSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'string', length: 32)]
    private string $status = 'draft';

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
}
