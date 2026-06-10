<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
