<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Subject "purchase" of the SearchKit tests: the simple form — a title column that is searched and shown, a plain
 * string status (a workflow place name), a string tenant key, a string id, an owner and a soft-delete date.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wfsk_purchase_request')]
class PurchaseRequest
{
    #[ORM\Column(name: 'deleted_at', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $id,
        #[ORM\Column(name: 'tenant_key', length: 40)]
        private string $tenantKey,
        #[ORM\Column(length: 190)]
        private string $title,
        #[ORM\Column(length: 32)]
        private string $place = 'open',
        #[ORM\Column(name: 'owner_identifier', length: 64)]
        private string $ownerIdentifier = '',
    ) {
        $this->createdAt = new DateTimeImmutable('2026-11-19 10:00:00');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function delete(): void
    {
        $this->deletedAt = new DateTimeImmutable('2026-11-19 11:00:00');
    }

    public function summary(): string
    {
        return $this->tenantKey . $this->title . $this->place . $this->ownerIdentifier . $this->createdAt->format('c') . ($this->deletedAt?->format('c') ?? '');
    }
}
