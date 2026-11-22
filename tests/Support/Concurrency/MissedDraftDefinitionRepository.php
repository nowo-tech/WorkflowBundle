<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

/**
 * A repository whose first draft lookup answers "no draft yet": the state a request saw just before a concurrent
 * request committed the scope's draft (2.2). It makes the window between WorkflowVersioning::createDraftOf()'s fast
 * path and its version claim deterministic, so the re-check under the claim has to find the winner's draft. With
 * holdPlainReads() every plain read of the scope stays stale too, as under an older REPEATABLE READ snapshot; the
 * as-committed read ({@see WorkflowDefinitionRepository::findVersionsAsCommitted()}) always reads the store.
 */
final class MissedDraftDefinitionRepository extends WorkflowDefinitionRepository
{
    private bool $missed = false;

    private bool $plainReadsStale = false;

    public function holdPlainReads(): self
    {
        $this->plainReadsStale = true;

        return $this;
    }

    public function findDraft(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        if (!$this->missed) {
            $this->missed = true;

            return null;
        }

        return $this->plainReadsStale ? null : parent::findDraft($slug, $tenant);
    }

    /**
     * @return list<WorkflowDefinition>
     */
    public function findVersions(string $slug, ?WorkflowTenant $tenant): array
    {
        return $this->plainReadsStale ? [] : parent::findVersions($slug, $tenant);
    }
}
