<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;

/**
 * @extends ServiceEntityRepository<WorkflowDefinition>
 */
class WorkflowDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowDefinition::class);
    }

    public function findOneBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<WorkflowDefinition> */
    public function findAllEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC']);
    }

    /** @return list<WorkflowDefinition> */
    public function findEnabledCandidates(?string $subjectClass): array
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

        /** @var list<WorkflowDefinition> $items */
        $items = $this->createListQueryBuilder()
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

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
            ->leftJoin('d.places', 'p')->addSelect('p')
            ->leftJoin('d.transitions', 't')->addSelect('t')
            ->leftJoin('d.matchRules', 'm')->addSelect('m')
            ->orderBy('d.name', 'ASC');
    }
}
