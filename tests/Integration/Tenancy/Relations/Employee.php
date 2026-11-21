<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy\Relations;

/**
 * A host entity (the requester of an expense claim), managed by the application's entity manager only.
 */
class Employee
{
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(private string $name)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
