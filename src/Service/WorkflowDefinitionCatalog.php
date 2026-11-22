<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowDefinitionCatalogInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionPage;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowTemplateInstallation;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

use function array_column;
use function array_map;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function max;
use function min;

/**
 * Default {@see WorkflowDefinitionCatalogInterface} (since 2.1): reads through {@see WorkflowDefinitionRepository} and
 * installs through the internal {@see WorkflowVersioning}, so every write goes through the bundle's private entity
 * manager and the clone lock and the registry invalidation behave as for {@see WorkflowVersionManager::cloneTemplate()}.
 *
 * The service holds no state between calls (REQ-RUNTIME-002): every call reads the store. The install is idempotent
 * and race-safe by construction: it reads the tenant's scope first, writes with the unique `tenant_key, slug, version`
 * key as the arbiter, and when that key refuses the write (a concurrent install won) it reads the scope again and
 * answers the winner's definition.
 */
final readonly class WorkflowDefinitionCatalog implements WorkflowDefinitionCatalogInterface
{
    public function __construct(
        private WorkflowVersioning $versioning,
        private WorkflowDefinitionRepository $repository,
    ) {
    }

    public function list(WorkflowTenant $tenant, int $page = 1, int $pageSize = 50): WorkflowDefinitionPage
    {
        $pageSize = max(1, $pageSize);
        $ids      = array_column($this->repository->tenantListing($tenant), 'id');
        $total    = count($ids);
        $pages    = max(1, (int) ceil($total / $pageSize));
        $page     = min(max(1, $page), $pages);
        $slice    = array_values(array_slice($ids, ($page - 1) * $pageSize, $pageSize));

        return new WorkflowDefinitionPage(
            array_map(
                static fn (WorkflowDefinition $definition): WorkflowDefinitionView => WorkflowDefinitionView::fromDefinition($definition),
                $this->repository->hydrate($slice),
            ),
            $total,
            $page,
            $pages,
            $pageSize,
        );
    }

    public function references(WorkflowTenant $tenant): array
    {
        return array_map(
            static fn (array $row): WorkflowDefinitionRef => new WorkflowDefinitionRef($row['id'], $row['slug'], $tenant, $row['version']),
            $this->repository->tenantListing($tenant),
        );
    }

    public function count(WorkflowTenant $tenant): int
    {
        return count($this->repository->tenantListing($tenant));
    }

    public function isInstalled(string $templateKey, WorkflowTenant $tenant): bool
    {
        return $templateKey !== '' && $this->repository->existsInScope($templateKey, $tenant);
    }

    public function installTemplate(string $templateKey, WorkflowTenant $tenant, bool $publish = false): WorkflowTemplateInstallation
    {
        $existing = $this->newest($templateKey, $tenant);
        if ($existing instanceof WorkflowDefinition) {
            return new WorkflowTemplateInstallation(WorkflowDefinitionView::fromDefinition($existing), false);
        }

        try {
            return new WorkflowTemplateInstallation(WorkflowDefinitionView::fromDefinition($this->versioning->cloneTemplate($templateKey, $tenant, $publish)), true);
        } catch (LogicException $refused) {
            // A concurrent install created the tenant's definition between the read and the write (the unique key
            // refused ours): that definition is the installed one. Anything else is the lock refusing the clone.
            $winner = $this->newest($templateKey, $tenant);
            if (!$winner instanceof WorkflowDefinition) {
                throw $refused;
            }

            return new WorkflowTemplateInstallation(WorkflowDefinitionView::fromDefinition($winner), false);
        }
    }

    /**
     * The newest stored version of the template key in exactly the tenant's scope, or null.
     */
    private function newest(string $templateKey, WorkflowTenant $tenant): ?WorkflowDefinition
    {
        if ($templateKey === '') {
            throw WorkflowNotFoundException::forSlug($templateKey);
        }

        return $this->repository->findVersions($templateKey, $tenant)[0] ?? null;
    }
}
