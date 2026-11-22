<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
use function sprintf;

/**
 * Default {@see WorkflowDefinitionCatalogInterface} (since 2.1): reads through {@see WorkflowDefinitionRepository} and
 * installs through the internal {@see WorkflowVersioning}, so every write goes through the bundle's private entity
 * manager and the clone lock and the registry invalidation behave as for {@see WorkflowVersionManager::cloneTemplate()}.
 *
 * The service holds no state between calls (REQ-RUNTIME-002): every call reads the store. The install is idempotent
 * and race-safe by construction: it reads the tenant's scope first, writes with the unique `tenant_key, slug, version`
 * key as the arbiter, and when that key refuses the write (a concurrent install won) it reads the winner as last
 * committed (since 2.1.1 also inside the caller's transaction) and answers the winner's definition.
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
            return new WorkflowTemplateInstallation(WorkflowDefinitionView::fromDefinition($this->winner($templateKey, $tenant, $refused)), false);
        }
    }

    /**
     * The definition a concurrent install stored, after cloneTemplate() refused this one (REQ-RUNTIME-003, audit
     * YP-L1).
     *
     * - The unique key refused the write (a concurrent install won): inside the caller's transaction the refused write
     *   was rolled back to the private manager's savepoint, the caller's transaction is untouched and the winner is
     *   read as last committed ({@see WorkflowDefinitionRepository::findVersionsAsCommitted()}: a locking read on
     *   MySQL / MariaDB, whose REPEATABLE READ snapshot may predate the winner's commit). When there was no savepoint
     *   (DBAL 3 with savepoints off) the refused write marked the caller's transaction rollback-only: nothing is read
     *   and a LogicException says so. When the caller's snapshot still hides the winner (PostgreSQL REPEATABLE READ /
     *   SERIALIZABLE, MariaDB with `innodb_snapshot_isolation`), a LogicException says so too. The transaction is never
     *   committed nor rolled back here.
     * - Any other refusal (the tenant already had the slug, the clone-structure lock): the scope is read again and the
     *   refusal is rethrown when it holds nothing.
     *
     * @throws LogicException when no winner can be read (see {@see WorkflowDefinitionCatalogInterface::installTemplate()})
     */
    private function winner(string $templateKey, WorkflowTenant $tenant, LogicException $refused): WorkflowDefinition
    {
        if (!$refused->getPrevious() instanceof UniqueConstraintViolationException) {
            return $this->newest($templateKey, $tenant) ?? throw $refused;
        }

        $connection = $this->repository->connection();
        if ($connection->isTransactionActive() && $connection->isRollbackOnly()) {
            throw new LogicException(sprintf('A concurrent install of workflow "%s" won, but the refused write marked your transaction rollback-only (no savepoint: turn savepoints on with DBAL 3). Roll it back and install again.', $templateKey), 0, $refused);
        }

        $winner = $this->repository->findVersionsAsCommitted($templateKey, $tenant)[0] ?? null;
        if ($winner instanceof WorkflowDefinition) {
            return $winner;
        }
        if ($connection->isTransactionActive()) {
            throw new LogicException(sprintf('A concurrent install of workflow "%s" won, but your transaction\'s snapshot predates it (REPEATABLE READ / SERIALIZABLE on PostgreSQL, or MariaDB with innodb_snapshot_isolation): read it again after your commit.', $templateKey), 0, $refused);
        }

        throw $refused;
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
