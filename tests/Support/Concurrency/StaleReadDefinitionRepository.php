<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

/**
 * A repository whose first reads of one tenant scope answer "nothing there yet": the state a request saw just before a
 * concurrent request committed the same row. It makes the check-then-write window of an install deterministic, so the
 * unique key (and not the read) has to decide the race.
 */
final class StaleReadDefinitionRepository extends WorkflowDefinitionRepository
{
    private bool $stale = true;

    /**
     * Ends the stale window: later reads see the store.
     */
    public function catchUp(): void
    {
        $this->stale = false;
    }

    public function existsInScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        return $tenant instanceof WorkflowTenant && $this->stale ? false : parent::existsInScope($slug, $tenant);
    }

    /**
     * @return list<WorkflowDefinition>
     */
    public function findVersions(string $slug, ?WorkflowTenant $tenant): array
    {
        if ($tenant instanceof WorkflowTenant && $this->stale) {
            $this->stale = false;

            return [];
        }

        return parent::findVersions($slug, $tenant);
    }
}
