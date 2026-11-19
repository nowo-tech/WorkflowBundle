<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A host's workflow request (e.g. a leave request) for the SearchKit group tests: a title, a marking, a tenant, an
 * owner the host's access rule reads, a private body and comment that must never be searched or shown, and an
 * archive date.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfsk_search_request')]
class SearchRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(name: 'archived_at', nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'tenant_id')]
        private int $tenantId,
        #[ORM\Column(length: 190, nullable: true)]
        private ?string $title,
        #[ORM\Column(length: 16, enumType: SearchRequestStatus::class)]
        private SearchRequestStatus $status = SearchRequestStatus::Pending,
        #[ORM\Column(name: 'owner_identifier', length: 64)]
        private string $ownerIdentifier = '',
        #[ORM\Column(type: 'text')]
        private string $body = '',
        #[ORM\Column(name: 'approver_note', length: 190)]
        private string $approverNote = '',
    ) {
        $this->updatedAt = new DateTimeImmutable('2026-11-19 10:00:00');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function archive(): void
    {
        $this->archivedAt = new DateTimeImmutable('2026-11-19 11:00:00');
    }

    public function body(): string
    {
        return $this->body . $this->approverNote . $this->ownerIdentifier . $this->tenantId . ($this->title ?? '') . $this->status->value . $this->updatedAt->format('c') . ($this->archivedAt?->format('c') ?? '');
    }
}
