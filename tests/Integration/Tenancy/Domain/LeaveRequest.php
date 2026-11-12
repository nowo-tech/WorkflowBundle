<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain;

/**
 * Plain-PHP host subject of the tenancy tests (DDD style: no bundle base class, XML mapping, ids as strings).
 */
class LeaveRequest
{
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    private string $status = 'requested';

    public function __construct(
        private string $uuid,
        private string $organization,
        private int $days,
        private string $type = 'vacation',
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOrganization(): string
    {
        return $this->organization;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function getType(): string
    {
        return $this->type;
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
