<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface;
use Nowo\WorkflowBundle\Model\WorkflowContext;

#[ORM\Entity]
#[ORM\Table(name: 'demo_purchase_order')]
class DemoPurchaseOrder implements WorkflowContextAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $tenant;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $region;

    #[ORM\Column(name: 'amount_tier', type: Types::STRING, length: 16)]
    private string $amountTier;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $status = 'draft';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $title, string $tenant, string $region, string $amountTier)
    {
        $this->title       = $title;
        $this->tenant      = $tenant;
        $this->region      = $region;
        $this->amountTier  = $amountTier;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getAmountTier(): string
    {
        return $this->amountTier;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getWorkflowContext(): WorkflowContext
    {
        return new WorkflowContext(
            subjectClass: self::class,
            parameters: [
                'tenant' => $this->tenant,
                'region' => $this->region,
                'amount_tier' => $this->amountTier,
            ],
        );
    }
}
