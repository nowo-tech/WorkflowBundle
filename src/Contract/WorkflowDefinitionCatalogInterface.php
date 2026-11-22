<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use LogicException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionPage;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowTemplateInstallation;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;

/**
 * Framework-free catalog port of a tenant's workflow definitions (since 2.1, DDD-host checklist B7): list them as read
 * models or references and install a global template for a tenant, with scalars, bundle value objects and views only
 * (never the `WorkflowDefinition` aggregate, never a Doctrine type). A host with a private entity manager and no
 * access to the bundle's repository uses this port instead of reading the definition tables.
 *
 * It is a separate port from {@see WorkflowVersionManagerInterface} so that hosts decorating or replacing that port
 * keep working unchanged. {@see WorkflowDefinitionCatalog} is the built-in implementation.
 *
 * A template key is the slug of a published global definition (a definition without a tenant). Tenant keys, slugs
 * and template keys are compared exactly (`acme` is not `Acme`): the SQL comparison only pre-filters and every row is
 * re-checked byte for byte. Nothing is memoised, every call reads the store (REQ-RUNTIME-002/003).
 */
interface WorkflowDefinitionCatalogInterface
{
    /**
     * One page of the definitions the tenant owns, ordered by name: the latest version (draft, published or retired)
     * of each slug. Global templates are not listed, and never another tenant's definition. A page past the last one
     * returns the last page; a page size below 1 is 1.
     */
    public function list(WorkflowTenant $tenant, int $page = 1, int $pageSize = 50): WorkflowDefinitionPage;

    /**
     * The references (ids included) of the same definitions as {@see self::list()}, without loading their places and
     * transitions, ordered by name.
     *
     * @return list<WorkflowDefinitionRef>
     */
    public function references(WorkflowTenant $tenant): array;

    /**
     * How many definitions (slugs) the tenant owns.
     */
    public function count(WorkflowTenant $tenant): int;

    /**
     * Whether the tenant owns any version of the template key (also a retired or draft one).
     */
    public function isInstalled(string $templateKey, WorkflowTenant $tenant): bool;

    /**
     * Installs the global template for the tenant, idempotently: when the tenant already owns a definition with the
     * template key (any version or status) nothing is written and that definition's newest version is returned with
     * `created = false`. Otherwise the current published version of the template is copied into the tenant as a draft
     * (version 1), or as a published version 1 with $publish.
     *
     * Two concurrent installs of the same template for one tenant leave exactly one definition (the unique
     * `tenant_key, slug, version` key decides; the loser returns the winner's definition with `created = false`).
     * Since 2.1.1 that also holds inside a transaction the caller opened: the refused write rolls back to its savepoint
     * only and the loser reads the winner as last committed (a locking read on MySQL / MariaDB, whose REPEATABLE READ
     * snapshot may predate the winner's commit; the shared locks last until the caller's transaction ends). The
     * caller's transaction is never committed or rolled back by the bundle. A LogicException remains only when the
     * winner cannot be read without breaking the caller's transaction: DBAL 3 with savepoints off (the refused write
     * marked that transaction rollback-only: roll it back and install again), or a transaction whose snapshot predates
     * the winner and cannot be read past (PostgreSQL REPEATABLE READ / SERIALIZABLE, MariaDB REPEATABLE READ with
     * `innodb_snapshot_isolation` on, its default since 11.6.2: re-read after the commit). Either way exactly one
     * definition is stored. A racing `$publish = true` loser may receive the winner's draft with `created = false`.
     *
     * @throws WorkflowNotFoundException when there is no published global template with that key
     * @throws WorkflowStructureLockedException when $publish is set and the lock refuses the clone
     * @throws LogicException when a concurrent install won inside the caller's transaction and the winner cannot be read without breaking it: DBAL 3 with savepoints off (transaction left rollback-only), or a snapshot older than the winner that cannot be read past (PostgreSQL REPEATABLE READ / SERIALIZABLE, MariaDB with innodb_snapshot_isolation; see above)
     */
    public function installTemplate(string $templateKey, WorkflowTenant $tenant, bool $publish = false): WorkflowTemplateInstallation;
}
