<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface;
use Nowo\WorkflowBundle\Model\WorkflowContext;

#[ORM\Entity]
#[ORM\Table(name: 'demo_document')]
class DemoDocument implements WorkflowContextAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(name: 'document_type', type: Types::STRING, length: 32)]
    private string $documentType;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $status = ['draft'];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $title, string $documentType = 'invoice')
    {
        $this->title        = $title;
        $this->documentType = $documentType;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    /** @return list<string> */
    public function getStatus(): array
    {
        return $this->status;
    }

    /** @param list<string> $status */
    public function setStatus(array $status): self
    {
        $this->status = array_values($status);

        return $this;
    }

    public function getWorkflowContext(): WorkflowContext
    {
        return new WorkflowContext(
            subjectClass: self::class,
            parameters: ['document_type' => $this->documentType],
        );
    }
}
