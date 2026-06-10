<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Resolves Symfony Workflow instances from database definitions.
 */
final class DatabaseWorkflowRegistry
{
    /** @var array<string, WorkflowInterface> */
    private array $cache = [];

    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly WorkflowDefinitionBuilder $builder,
    ) {
    }

    public function get(string $slug): WorkflowInterface
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $definition = $this->repository->findOneBySlug($slug);
        if ($definition === null || !$definition->isEnabled()) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        return $this->cache[$slug] = $this->createWorkflow($definition);
    }

    public function has(string $slug): bool
    {
        try {
            $this->get($slug);

            return true;
        } catch (WorkflowNotFoundException) {
            return false;
        }
    }

    public function invalidate(?string $slug = null): void
    {
        if ($slug === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$slug]);
    }

    public function createWorkflow(WorkflowDefinition $definition): WorkflowInterface
    {
        $symfonyDefinition = $this->builder->build($definition);
        $markingStore      = new MethodMarkingStore(
            singleState: $definition->getType() === WorkflowType::StateMachine,
            property: $definition->getMarkingProperty(),
        );

        return match ($definition->getType()) {
            WorkflowType::StateMachine => new StateMachine(
                $symfonyDefinition,
                $markingStore,
                null,
                $definition->getSlug(),
            ),
            WorkflowType::Workflow => new Workflow(
                $symfonyDefinition,
                $markingStore,
                null,
                $definition->getSlug(),
            ),
        };
    }
}
