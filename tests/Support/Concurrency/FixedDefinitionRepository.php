<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use ReflectionClass;

use function array_values;

/**
 * A definition repository that serves fixed definitions (no database): race tests and their child process.
 */
final class FixedDefinitionRepository extends WorkflowDefinitionRepository
{
    /** @var array<string, WorkflowDefinition> */
    private array $definitions = [];

    public static function with(WorkflowDefinition ...$definitions): self
    {
        $repository = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        foreach ($definitions as $definition) {
            $repository->definitions[$definition->getSlug()] = $definition;
        }

        return $repository;
    }

    public function findOneBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    public function findCurrent(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    public function findEnabledCandidates(string|array|null $subjectClass): array
    {
        return array_values($this->definitions);
    }
}
