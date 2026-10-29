<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use SortDirection;

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
     * Finds a definition by slug, enabled or not.
     */
    public function findOneBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Returns every enabled definition ordered by name.
     *
     * @return list<WorkflowDefinition>
     */
    public function findAllEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC']);
    }

    /**
     * Counts the enabled definitions.
     */
    public function countEnabled(): int
    {
        return $this->count(['enabled' => true]);
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
        $criteria = ['enabled' => true];
        if ($subjectClass !== null) {
            $criteria['subjectClass'] = $subjectClass;
        }

        return $this->findBy($criteria, ['priority' => 'DESC', 'name' => 'ASC']);
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
    public function paginateByName(int $page, int $pageSize): array
    {
        $page = max(1, $page);

        $total = (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($pageSize <= 0) {
            /** @var list<WorkflowDefinition> $items */
            $items = $this->createListQueryBuilder()
                ->getQuery()
                ->getResult();
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
        $items = $this->createListQueryBuilder()
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
        $this->loadListCollections($items);

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pages,
            'page_size' => $pageSize,
        ];
    }

    private function createListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.name', SortDirection::Ascending);
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
