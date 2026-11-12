<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The definition version a subject started with (since 1.15, tenancy only): in-flight subjects keep running the
 * version they started with when a newer one is published or a tenant customizes a global template.
 *
 * Keyed by the subject's tenant, the workflow slug and the host's opaque subject reference; written and read by the
 * bundle with plain DBAL statements (never through an application's unit of work). No personal data: the subject
 * reference is the host's id of a business object (see docs/MULTITENANCY.md, "GDPR").
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_subject_pin')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_subject_pin', columns: ['tenant_key', 'workflow_slug', 'subject_ref'])]
class WorkflowSubjectPin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    /**
     * Creates a pin of a subject on a definition version.
     */
    public function __construct(
        #[ORM\Column(name: 'tenant_key', type: Types::STRING, length: 128)]
        private string $tenantKey,
        #[ORM\Column(name: 'workflow_slug', type: Types::STRING, length: 128)]
        private string $workflowSlug,
        #[ORM\Column(name: 'subject_ref', type: Types::STRING, length: 128)]
        private string $subjectRef,
        #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class)]
        #[ORM\JoinColumn(name: 'definition_id', nullable: false, onDelete: 'CASCADE')]
        private WorkflowDefinition $definition,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    public function getWorkflowSlug(): string
    {
        return $this->workflowSlug;
    }

    public function getSubjectRef(): string
    {
        return $this->subjectRef;
    }

    public function getDefinition(): WorkflowDefinition
    {
        return $this->definition;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
