<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Exception\WorkflowNameCollisionException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function in_array;

/**
 * Resolves Symfony Workflow instances from database definitions.
 *
 * Built workflows are memoized for the current main request only, so long-running workers
 * (FrankenPHP, RoadRunner, Messenger) never serve definitions loaded by an earlier request.
 * Outside of an HTTP request (CLI, Messenger) nothing is memoized.
 *
 * Workflows are built with the application event dispatcher, so the standard Symfony workflow events
 * (workflow.guard, workflow.<slug>.guard, workflow.<slug>.transition.<name>, …) are dispatched with the
 * definition slug as workflow name: guard listeners can block transitions and listeners can react to them.
 */
final class DatabaseWorkflowRegistry implements WorkflowRegistryInterface, ResetInterface
{
    /** @var array<string, WorkflowInterface> */
    private array $cache = [];

    /** @var WeakReference<Request>|null */
    private ?WeakReference $cacheOwner = null;

    /**
     * Creates the registry with the definition repository, the definition builder, the request stack and the event
     * dispatcher the built workflows dispatch their events with (null: no workflow events, the 1.x behaviour).
     *
     * $frameworkWorkflowNames are the names of the workflows configured under framework.workflows: with an event
     * dispatcher, a definition whose slug is one of them is refused (WorkflowNameCollisionException), because both
     * workflows would dispatch the same workflow.<name>.* events.
     *
     * @param list<string> $frameworkWorkflowNames
     */
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly WorkflowDefinitionBuilder $builder,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly array $frameworkWorkflowNames = [],
        private readonly ?WorkflowDefinitionLocator $locator = null,
    ) {
    }

    /**
     * The workflow a subject runs (since 1.15): with tenancy (`tenancy.tenant_resolver`), its pinned version, else its
     * tenant's version, else the global template, never another tenant's; without tenancy the same as {@see get()}.
     */
    public function forSubject(object $subject, string $workflowSlug): WorkflowInterface
    {
        if (!$this->locator instanceof WorkflowDefinitionLocator || !$this->locator->tenancyEnabled()) {
            return $this->get($workflowSlug);
        }

        return $this->forDefinition($this->locator->forSubject($subject, $workflowSlug));
    }

    /**
     * The workflow of one stored definition version, memoized for the current main request only (since 1.15).
     */
    public function forDefinition(WorkflowDefinition $definition): WorkflowInterface
    {
        $request = $this->currentCacheOwner();
        $key     = '#' . (string) $definition->getId();

        if ($request instanceof Request && $definition->getId() !== null && isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $workflow = $this->createWorkflow($definition);

        if ($request instanceof Request && $definition->getId() !== null) {
            $this->cache[$key] = $workflow;
        }

        return $workflow;
    }

    /**
     * Returns the workflow of an enabled definition, memoized for the current main request only.
     */
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

    /**
     * Tells whether an enabled definition exists for the slug.
     */
    public function has(string $slug): bool
    {
        try {
            $this->get($slug);

            return true;
        } catch (WorkflowNotFoundException) {
            return false;
        }
    }

    /**
     * Drops the memoized workflow of one slug, or of every slug when null.
     */
    public function invalidate(?string $slug = null): void
    {
        if ($slug === null) {
            $this->cache = [];

            return;
        }

        foreach ($this->cache as $key => $workflow) {
            if ($key === $slug || $workflow->getName() === $slug) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Clears the memoized workflows between requests (kernel.reset).
     */
    public function reset(): void
    {
        $this->cache      = [];
        $this->cacheOwner = null;
    }

    /**
     * Builds a Symfony Workflow or StateMachine from a definition entity.
     *
     * @throws WorkflowNameCollisionException when events are dispatched and the slug is a framework workflow name
     */
    public function createWorkflow(WorkflowDefinition $definition): WorkflowInterface
    {
        if ($this->eventDispatcher instanceof EventDispatcherInterface && in_array($definition->getSlug(), $this->frameworkWorkflowNames, true)) {
            throw WorkflowNameCollisionException::forSlug($definition->getSlug());
        }

        $symfonyDefinition = $this->builder->build($definition);
        $markingStore      = new MethodMarkingStore(
            singleState: $definition->getType() === WorkflowType::StateMachine,
            property: $definition->getMarkingProperty(),
        );

        return match ($definition->getType()) {
            WorkflowType::StateMachine => new StateMachine(
                $symfonyDefinition,
                $markingStore,
                $this->eventDispatcher,
                $definition->getSlug(),
            ),
            WorkflowType::Workflow => new Workflow(
                $symfonyDefinition,
                $markingStore,
                $this->eventDispatcher,
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
