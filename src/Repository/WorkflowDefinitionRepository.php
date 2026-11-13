<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowDefinitionStatus;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use SortDirection;

use function array_column;
use function array_filter;
use function array_flip;
use function array_map;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function mb_strtolower;
use function min;
use function usort;

/**
 * Doctrine repository of workflow definitions.
 *
 * @extends ServiceEntityRepository<WorkflowDefinition>
 */
class WorkflowDefinitionRepository extends ServiceEntityRepository
{
    /**
     * Creates the repository for the WorkflowDefinition entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowDefinition::class);
    }

    /**
     * Persists a definition (new or changed) through the manager of this repository, and flushes that manager unless
     * $flush is false (since 1.14).
     *
     * Since 1.14 the definitions are managed by the bundle's private entity manager: a definition loaded through this
     * repository is not managed by the application's entity manager, so change it and save it here. The flush writes
     * only definition rows (the application's pending changes are not in that unit of work).
     */
    public function save(WorkflowDefinition $definition, bool $flush = true): void
    {
        $this->getEntityManager()->persist($definition);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Flushes the repository's manager (the private manager since 1.14): the definitions only (since 1.15).
     */
    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * The DBAL connection of the definitions (since 1.15, version pins).
     */
    public function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * The mapping of a bundle entity on the definitions' manager, table prefix applied (since 1.15).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     */
    public function bundleMetadata(string $class): ClassMetadata
    {
        return $this->getEntityManager()->getClassMetadata($class);
    }

    /**
     * Removes a definition (with its places, transitions and match rules) through the manager of this repository, and
     * flushes that manager unless $flush is false (since 1.14).
     */
    public function remove(WorkflowDefinition $definition, bool $flush = true): void
    {
        $this->getEntityManager()->remove($definition);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * The ids of every stored definition (all tenants, versions and statuses), ascending (since 1.15.2, lint).
     *
     * @return list<int>
     */
    public function findAllIds(): array
    {
        $ids = [];
        foreach ($this->createQueryBuilder('d')->select('d.id')->orderBy('d.id', 'ASC')->getQuery()->getScalarResult() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Detaches read-only definitions and their places, transitions and match rules from the manager (since 1.15.2, so
     * a scan over every version keeps a bounded memory; nothing is flushed or cleared).
     *
     * @param iterable<WorkflowDefinition> $definitions
     */
    public function release(iterable $definitions): void
    {
        $manager = $this->getEntityManager();
        foreach ($definitions as $definition) {
            foreach ([...$definition->getPlaces(), ...$definition->getTransitions(), ...$definition->getMatchRules()] as $child) {
                $manager->detach($child);
            }
            $manager->detach($definition);
        }
    }

    /**
     * Finds a definition by slug, enabled or not.
     */
    public function findOneBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->findCurrent($slug, null);
    }

    /**
     * The current published version of a definition of exactly this scope (null tenant = global template), enabled
     * or not (since 1.15). Never another tenant's definition. Since 1.16 a retired version is not current: a retired
     * tenant clone has no current version (its subjects without a pin run the template).
     */
    public function findCurrent(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        foreach ($this->scopeRows($slug, $tenant) as $row) {
            if ($row->isPublished()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The newest retired version of a definition of exactly this scope, if any (since 1.16).
     */
    public function findRetired(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        foreach ($this->scopeRows($slug, $tenant) as $row) {
            if ($row->isRetired()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether any tenant has a definition with this slug (since 1.16; the slug is re-checked byte for byte).
     */
    public function existsForAnyTenant(string $slug): bool
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.slug', 'd.tenantKey')
            ->where('d.slug = :slug')
            ->andWhere("d.tenantKey <> ''")
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            if (is_array($row) && ($row['slug'] ?? null) === $slug && ($row['tenantKey'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One version of a definition of exactly this scope (since 1.16), any status.
     */
    public function findVersion(string $slug, ?WorkflowTenant $tenant, int $version): ?WorkflowDefinition
    {
        foreach ($this->scopeRows($slug, $tenant) as $row) {
            if ($row->getVersion() === $version) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The definition a subject of the tenant runs for a slug (since 1.15): the tenant's current published version
     * when the tenant has one (even disabled: a tenant that disabled its version opted out, there is no fallback),
     * else the global template's. Never another tenant's definition.
     */
    public function findForTenant(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        if ($tenant instanceof WorkflowTenant) {
            $own = $this->findCurrent($slug, $tenant);
            if ($own instanceof WorkflowDefinition) {
                return $own;
            }
        }

        return $this->findCurrent($slug, null);
    }

    /**
     * The open draft of a definition of exactly this scope, if any (since 1.15; the newest one should several exist).
     */
    public function findDraft(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        foreach ($this->scopeRows($slug, $tenant) as $row) {
            if ($row->isDraft()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether any version of the slug exists in exactly this scope (since 1.15).
     */
    public function existsInScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        return $this->scopeRows($slug, $tenant) !== [];
    }

    /**
     * The next version number of a slug in exactly this scope (since 1.15). Deliberately computed over every row the
     * SQL comparison returns (under a case-insensitive collation that may include a case variant of the key): the
     * number is then at least the exact maximum and can never collide in a unique index that folds the keys together;
     * no row of another tenant is ever returned, only a number (audit NO-v N3).
     */
    public function nextVersion(string $slug, ?WorkflowTenant $tenant): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.version)')
            ->where('d.slug = :slug')
            ->andWhere('d.tenantKey = :tenant')
            ->setParameter('slug', $slug)
            ->setParameter('tenant', WorkflowTenant::storageKey($tenant))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }

    /**
     * Every version of a slug in exactly this scope (since 1.15), newest first.
     *
     * @return list<WorkflowDefinition>
     */
    public function findVersions(string $slug, ?WorkflowTenant $tenant): array
    {
        return $this->scopeRows($slug, $tenant);
    }

    /**
     * Every version of a slug in exactly this scope, newest first (audit NO M1): the SQL comparison only pre-filters
     * (a case- or accent-insensitive collation also returns `Acme` for `acme`); slug and tenant key are re-checked
     * byte for byte in PHP and any other row is dropped.
     *
     * @return list<WorkflowDefinition>
     */
    private function scopeRows(string $slug, ?WorkflowTenant $tenant): array
    {
        $key = WorkflowTenant::storageKey($tenant);
        /** @var list<WorkflowDefinition> $rows */
        $rows = $this->findBy(['slug' => $slug, 'tenantKey' => $key], ['version' => 'DESC', 'id' => 'DESC']);

        return array_values(array_filter($rows, static fn (WorkflowDefinition $row): bool => $row->getSlug() === $slug && $row->getTenantKey() === $key));
    }

    /**
     * Returns every enabled definition ordered by name.
     *
     * @return list<WorkflowDefinition>
     */
    public function findAllEnabled(): array
    {
        return $this->findEnabledCandidates(null);
    }

    /**
     * Counts the enabled definitions (global, current published versions).
     */
    public function countEnabled(): int
    {
        return $this->countEnabledInScope(null, false);
    }

    /**
     * Counts the enabled definitions the list of a UI scope shows (since 1.15, see {@see paginateByName()}).
     */
    public function countEnabledInScope(?WorkflowTenant $tenant, bool $tenancy): int
    {
        $count = 0;
        foreach ($this->scopeListing($tenant, $tenancy) as $row) {
            $count += $row['enabled'] ? 1 : 0;
        }

        return $count;
    }

    /**
     * Returns the enabled definitions of the given subject class(es), highest priority first.
     *
     * @param list<string>|string|null $subjectClass One class, a class hierarchy (IN), or null for all
     *
     * @return list<WorkflowDefinition>
     */
    public function findEnabledCandidates(string|array|null $subjectClass): array
    {
        return $this->findEnabledCandidatesForTenant($subjectClass, null);
    }

    /**
     * The enabled definitions a subject of the tenant may run (since 1.15), highest priority first: for each slug,
     * the tenant's current published version when the tenant has one, else the global template's current published
     * version. A slug the tenant disabled is left out (no fallback to the template). Never another tenant's.
     *
     * @param list<string>|string|null $subjectClass One class, a class hierarchy (IN), or null for all
     *
     * @return list<WorkflowDefinition>
     */
    public function findEnabledCandidatesForTenant(string|array|null $subjectClass, ?WorkflowTenant $tenant): array
    {
        $keys = $tenant instanceof WorkflowTenant ? ['', $tenant->key] : [''];
        $qb   = $this->createQueryBuilder('d')
            ->where('d.tenantKey IN (:tenants)')
            ->andWhere('d.status = :published')
            ->setParameter('tenants', $keys)
            ->setParameter('published', WorkflowDefinitionStatus::Published->value)
            ->orderBy('d.priority', SortDirection::Descending)
            ->addOrderBy('d.name', SortDirection::Ascending)
            ->addOrderBy('d.version', SortDirection::Descending);
        if ($subjectClass !== null) {
            $qb->andWhere('d.subjectClass IN (:classes)')->setParameter('classes', (array) $subjectClass);
        }
        /** @var list<WorkflowDefinition> $rows */
        $rows = $qb->getQuery()->getResult();
        // The SQL IN only pre-filters (collation): another tenant's row is dropped here (audit NO M1).
        $rows = array_values(array_filter($rows, static fn (WorkflowDefinition $row): bool => in_array($row->getTenantKey(), $keys, true)));

        // Current version per (scope, slug): the first row seen (highest version within the same priority and name;
        // a lower version may sort elsewhere, so keep the highest explicitly).
        $current = [];
        foreach ($rows as $row) {
            $key = $row->getTenantKey() . "\0" . $row->getSlug();
            if (!isset($current[$key]) || $current[$key]->getVersion() < $row->getVersion()) {
                $current[$key] = $row;
            }
        }

        $shadowed = $tenant instanceof WorkflowTenant ? array_flip($this->publishedSlugs($tenant)) : [];
        $result   = [];
        foreach ($rows as $row) {
            $key = $row->getTenantKey() . "\0" . $row->getSlug();
            if (($current[$key] ?? null) !== $row || !$row->isEnabled()) {
                continue;
            }
            if ($row->getTenantKey() === '' && isset($shadowed[$row->getSlug()])) {
                continue;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Slugs the tenant has a published version of (any subject class), since 1.15.
     *
     * @return list<string>
     */
    private function publishedSlugs(WorkflowTenant $tenant): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.slug', 'd.tenantKey')
            ->where('d.tenantKey = :tenant')
            ->andWhere('d.status = :published')
            ->setParameter('tenant', $tenant->key)
            ->setParameter('published', WorkflowDefinitionStatus::Published->value)
            ->getQuery()
            ->getScalarResult();

        $slugs = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['tenantKey'] ?? null) === $tenant->key && is_string($row['slug'] ?? null)) {
                $slugs[$row['slug']] = $row['slug'];
            }
        }

        return array_values($slugs);
    }

    /**
     * Paginated list ordered by name, with associations hydrated to avoid N+1 on list pages.
     *
     * Since 1.15 the scope (tenant, latest version per slug, templates the tenant has not customized) is computed in PHP
     * from a light scalar query, with every tenant key and slug compared exactly (audit NO-v N3: no SQL comparison,
     * count or MAX can mix keys that a database collation folds together); only the page's entities are hydrated.
     *
     * @return array{
     *     items: list<WorkflowDefinition>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     page_size: int
     * }
     */
    public function paginateByName(int $page, int $pageSize, ?WorkflowTenant $tenant = null, bool $tenancy = false): array
    {
        $page  = max(1, $page);
        $ids   = array_column($this->scopeListing($tenant, $tenancy), 'id');
        $total = count($ids);
        $pages = $pageSize > 0 ? max(1, (int) ceil($total / $pageSize)) : 1;
        $page  = $pageSize > 0 ? min($page, $pages) : 1;
        $slice = $pageSize > 0 ? array_slice($ids, ($page - 1) * $pageSize, $pageSize) : $ids;

        $byId = [];
        if ($slice !== []) {
            /** @var list<WorkflowDefinition> $rows */
            $rows = $this->createQueryBuilder('d')->where('d.id IN (:ids)')->setParameter('ids', $slice)->getQuery()->getResult();
            foreach ($rows as $row) {
                $byId[$row->getId()] = $row;
            }
        }
        $items = [];
        foreach ($slice as $id) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }
        $this->loadListCollections($items);

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'page_size' => max(0, $pageSize),
        ];
    }

    /**
     * The definitions a UI scope lists (since 1.15), one row per slug, ordered by name:
     *
     * - without tenancy: the current published version of each global definition (every 1.14 definition);
     * - with tenancy: the latest version (draft, published or retired) of each definition of the scope (null = the
     *   global templates) and, for a tenant, the current published version of each global template the tenant has not
     *   customized yet or whose clone is retired (since 1.16). Never another tenant's definition.
     *
     * @return list<array{id: int, enabled: bool}>
     */
    private function scopeListing(?WorkflowTenant $tenant, bool $tenancy): array
    {
        $own  = $tenancy ? WorkflowTenant::storageKey($tenant) : '';
        $keys = $own === '' ? [''] : ['', $own];
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.id', 'd.tenantKey', 'd.slug', 'd.version', 'd.status', 'd.name', 'd.enabled')
            ->where('d.tenantKey IN (:keys)')
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getArrayResult();

        // Latest version per exact (tenant key, slug): drafts count only in the scope's own rows with tenancy.
        $latest     = [];
        $customized = [];
        foreach ($rows as $row) {
            $key  = $row['tenantKey'] ?? null;
            $slug = $row['slug'] ?? null;
            if (!is_string($key) || !is_string($slug) || !in_array($key, $keys, true)) {
                continue;
            }
            $status    = $row['status'] ?? null;
            $published = ($status instanceof WorkflowDefinitionStatus ? $status->value : $status) === WorkflowDefinitionStatus::Published->value;
            // Since 1.16: a clone whose versions are all retired does not hide the template (new subjects run it).
            if ($key === $own && $own !== '' && ($status instanceof WorkflowDefinitionStatus ? $status : WorkflowDefinitionStatus::tryFrom((string) $status)) !== WorkflowDefinitionStatus::Retired) {
                $customized[$slug] = true;
            }
            $draftsListed = $tenancy && $key === $own;
            if (!$published && !$draftsListed) {
                continue;
            }
            $index = $key . "\0" . $slug;
            if (!isset($latest[$index]) || (int) $row['version'] > (int) $latest[$index]['version']) {
                $latest[$index] = $row;
            }
        }

        $listing = [];
        foreach ($latest as $row) {
            if ($row['tenantKey'] === '' && $own !== '' && isset($customized[$row['slug']])) {
                continue;
            }
            $listing[] = $row;
        }
        usort($listing, static fn (array $a, array $b): int => [mb_strtolower((string) $a['name']), (string) $a['name'], (string) $b['tenantKey'], (int) $a['id']] <=> [mb_strtolower((string) $b['name']), (string) $b['name'], (string) $a['tenantKey'], (int) $b['id']]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'enabled' => (bool) $row['enabled']], $listing);
    }

    /**
     * Initializes places, transitions and match rules of the listed definitions with one query per collection.
     *
     * Fetch-joining the three collections in one query returned places × transitions × rules rows per definition
     * (a cartesian product); one `IN (:ids)` fetch-join per collection fills the already managed definitions instead
     * (1 + 3 queries per page, no lazy load per row).
     *
     * @param list<WorkflowDefinition> $items
     */
    private function loadListCollections(array $items): void
    {
        if ($items === []) {
            return;
        }

        foreach (['places', 'transitions', 'matchRules'] as $collection) {
            $this->createQueryBuilder('d')
                ->leftJoin('d.' . $collection, 'c')->addSelect('c')
                ->where('d IN (:items)')
                ->setParameter('items', $items)
                ->getQuery()
                ->getResult();
        }
    }
}
