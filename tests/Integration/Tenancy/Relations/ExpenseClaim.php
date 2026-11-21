<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy\Relations;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\TenantSubject;

/**
 * A host subject with relations: a ManyToOne to another host entity ({@see Employee}) and an optional ManyToOne to the
 * bundle's definition (the version the host records for its own reports), set from the host manager with
 * `getReference()` as UPGRADING.md "To 2.0.0" says.
 */
class ExpenseClaim implements TenantSubject
{
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    private string $status = 'requested';

    private ?Employee $approver = null;

    private ?WorkflowDefinition $workflowVersion = null;

    public function __construct(
        private string $uuid,
        private string $organization,
        private Employee $requester,
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

    public function getRequester(): Employee
    {
        return $this->requester;
    }

    public function getApprover(): ?Employee
    {
        return $this->approver;
    }

    public function setApprover(?Employee $approver): void
    {
        $this->approver = $approver;
    }

    public function getWorkflowVersion(): ?WorkflowDefinition
    {
        return $this->workflowVersion;
    }

    public function setWorkflowVersion(?WorkflowDefinition $workflowVersion): void
    {
        $this->workflowVersion = $workflowVersion;
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
