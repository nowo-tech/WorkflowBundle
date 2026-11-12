<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowDefinitionStatus;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use SortDirection;

use function array_filter;
use function array_flip;
use function array_values;
use function ceil;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;

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
     * Finds a definition by slug, enabled or not.
     */
    public function findOneBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->findCurrent($slug, null);
    }

    /**
     * The current published version of a definition of exactly this scope (null tenant = global template), enabled
     * or not (since 1.15). Never another tenant's definition.
     */
    public function findCurrent(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        foreach ($this->scopeRows($slug, $tenant) as $row) {
            if (!$row->isDraft()) {
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
     * The next version number of a slug in exactly this scope (since 1.15).
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
        return (int) $this->scopeQueryBuilder($tenant, $tenancy)
            ->select('COUNT(d.id)')
            ->andWhere('d.enabled = true')
            ->getQuery()
            ->getSingleScalarResult();
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
        $page = max(1, $page);

        $total = (int) $this->scopeQueryBuilder($tenant, $tenancy)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($pageSize <= 0) {
            /** @var list<WorkflowDefinition> $items */
            $items = $this->createListQueryBuilder($tenant, $tenancy)
                ->getQuery()
                ->getResult();
            $items = $this->inScope($items, $tenant, $tenancy);
            $this->loadListCollections($items);

            return [
                'items'     => $items,
                'total'     => $total,
                'page'      => 1,
                'pages'     => 1,
                'page_size' => 0,
            ];
        }

        $pages = max(1, (int) ceil($total / $pageSize));
        $page  = min($page, $pages);

        // No collection join in the page query: a plain LIMIT pages definitions, not joined rows.
        /** @var list<WorkflowDefinition> $items */
        $items = $this->createListQueryBuilder($tenant, $tenancy)
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
        $items = $this->inScope($items, $tenant, $tenancy);
        $this->loadListCollections($items);

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'page_size' => $pageSize,
        ];
    }

    /**
     * The SQL scope only pre-filters (collation): rows of any other tenant key are dropped (audit NO M1).
     *
     * @param list<WorkflowDefinition> $items
     *
     * @return list<WorkflowDefinition>
     */
    private function inScope(array $items, ?WorkflowTenant $tenant, bool $tenancy): array
    {
        $keys = $tenancy && $tenant instanceof WorkflowTenant ? ['', $tenant->key] : [''];

        return array_values(array_filter($items, static fn (WorkflowDefinition $row): bool => in_array($row->getTenantKey(), $keys, true)));
    }

    private function createListQueryBuilder(?WorkflowTenant $tenant, bool $tenancy): QueryBuilder
    {
        return $this->scopeQueryBuilder($tenant, $tenancy)
            ->orderBy('d.name', SortDirection::Ascending)
            ->addOrderBy('d.tenantKey', SortDirection::Descending);
    }

    /**
     * The definitions a UI scope lists (since 1.15), one row per slug:
     *
     * - without tenancy: the global definitions (their current version; every 1.14 definition);
     * - with tenancy: the latest version (draft or published) of each definition of the scope (null = the global
     *   templates) and, for a tenant, the current published version of each global template the tenant has not
     *   customized yet. Never another tenant's definition.
     */
    private function scopeQueryBuilder(?WorkflowTenant $tenant, bool $tenancy): QueryBuilder
    {
        $em  = $this->getEntityManager();
        $own = $em->createQueryBuilder()
            ->select('MAX(v1.version)')
            ->from(WorkflowDefinition::class, 'v1')
            ->where('v1.tenantKey = d.tenantKey')
            ->andWhere('v1.slug = d.slug');
        $qb = $this->createQueryBuilder('d');

        if (!$tenancy || !$tenant instanceof WorkflowTenant) {
            if (!$tenancy) {
                $own->andWhere('v1.status = :published');
                $qb->setParameter('published', WorkflowDefinitionStatus::Published->value)
                    ->andWhere('d.status = :published');
            }

            return $qb->andWhere("d.tenantKey = ''")
                ->andWhere('d.version = (' . $own->getDQL() . ')');
        }

        $template = $em->createQueryBuilder()
            ->select('MAX(v2.version)')
            ->from(WorkflowDefinition::class, 'v2')
            ->where("v2.tenantKey = ''")
            ->andWhere('v2.slug = d.slug')
            ->andWhere('v2.status = :published');
        $customized = $em->createQueryBuilder()
            ->select('v3.slug')
            ->from(WorkflowDefinition::class, 'v3')
            ->where('v3.tenantKey = :tenant');

        return $qb
            ->where('(d.tenantKey = :tenant AND d.version = (' . $own->getDQL() . '))'
                . " OR (d.tenantKey = '' AND d.status = :published AND d.version = (" . $template->getDQL() . ') AND d.slug NOT IN (' . $customized->getDQL() . '))')
            ->setParameter('tenant', $tenant->key)
            ->setParameter('published', WorkflowDefinitionStatus::Published->value);
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
