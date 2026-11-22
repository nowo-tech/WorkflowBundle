<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Concurrency\DefinitionVersionSequence;

/**
 * The version counter of one definition scope (tenant key and slug), since 2.2 (REQ-RUNTIME-003 rule 4).
 *
 * `last_version` is the highest version number handed out in the scope. A new version claims the next number with one
 * atomic `UPDATE … SET last_version = last_version + 1`, in the same transaction as the version's insert
 * ({@see DefinitionVersionSequence}); the row lock it takes serialises the writers of that scope until they commit. The
 * row is created on the first claim (seeded from the versions already stored, decided by the primary key), so an
 * upgraded database needs no data migration. It is written and read with plain DBAL statements only, never through a
 * unit of work, and holds no personal data.
 */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_definition_sequence')]
class WorkflowDefinitionSequence
{
    /**
     * Creates the counter of a scope.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'tenant_key', type: Types::STRING, length: 128)]
        private string $tenantKey,
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 128)]
        private string $slug,
        #[ORM\Column(name: 'last_version', type: Types::INTEGER)]
        private int $lastVersion = 0,
    ) {
    }

    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLastVersion(): int
    {
        return $this->lastVersion;
    }
}
