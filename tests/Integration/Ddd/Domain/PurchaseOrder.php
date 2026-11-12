<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Ddd\Domain;

/**
 * A DDD host aggregate: plain PHP, mapped in XML by the host (tests/Integration/Ddd/mapping), no bundle base class,
 * trait or interface.
 */
class PurchaseOrder
{
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    private string $status = 'draft';

    public function __construct(private string $reference)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
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
