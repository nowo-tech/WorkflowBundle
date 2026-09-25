<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

/**
 * Resolves Symfony Workflow instances from database definitions.
 *
 * Built workflows are memoized for the current main request only, so long-running workers
 * (FrankenPHP, RoadRunner, Messenger) never serve definitions loaded by an earlier request.
 * Outside of an HTTP request (CLI, Messenger) nothing is memoized.
 */
final class DatabaseWorkflowRegistry implements WorkflowRegistryInterface, ResetInterface
{
    /** @var array<string, WorkflowInterface> */
    private array $cache = [];

    /** @var WeakReference<Request>|null */
    private ?WeakReference $cacheOwner = null;

    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly WorkflowDefinitionBuilder $builder,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function get(string $slug): WorkflowInterface
    {
        $request = $this->currentCacheOwner();

        if ($request instanceof Request && isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $definition = $this->repository->findOneBySlug($slug);
        if (!$definition instanceof WorkflowDefinition || !$definition->isEnabled()) {
            throw WorkflowNotFoundException::forSlug($slug);
        }

        $workflow = $this->createWorkflow($definition);

        if ($request instanceof Request) {
            $this->cache[$slug] = $workflow;
        }

        return $workflow;
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

    public function reset(): void
    {
        $this->cache      = [];
        $this->cacheOwner = null;
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

    /**
     * Returns the main request that owns the memo, dropping the memo when the main request changed.
     */
    private function currentCacheOwner(): ?Request
    {
        $request = $this->requestStack?->getMainRequest();

        if (!$request instanceof Request) {
            $this->reset();

            return null;
        }

        if ($this->cacheOwner?->get() !== $request) {
            $this->cache      = [];
            $this->cacheOwner = WeakReference::create($request);
        }

        return $request;
    }
}
