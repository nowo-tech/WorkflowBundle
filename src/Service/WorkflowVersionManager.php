<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

use function array_map;

/**
 * Default {@see WorkflowVersionManagerInterface} (since 1.19): {@see WorkflowVersioningInterface} behind a
 * framework-free surface, the same split as {@see WorkflowRuntime} over the deprecated workflow registry port.
 *
 * Every write is delegated to the versioning SPI, so a host decorator or replacement of it keeps running and the
 * owner rule on deletes (no delete while subjects are pinned) holds here as well. A reference is resolved against the
 * store on every call — scope first (slug and tenant, byte for byte), then the row id — so a reference can never
 * reach a version of another tenant and nothing is memoised between calls (REQ-RUNTIME-002).
 */
final readonly class WorkflowVersionManager implements WorkflowVersionManagerInterface
{
    public function __construct(
        private WorkflowVersioningInterface $versioning,
        private WorkflowDefinitionRepository $repository,
    ) {
    }

    public function currentVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView
    {
        $current = $this->repository->findCurrent($slug, $tenant);

        return $current instanceof WorkflowDefinition ? WorkflowDefinitionView::fromDefinition($current) : null;
    }

    public function draftVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView
    {
        $draft = $this->repository->findDraft($slug, $tenant);

        return $draft instanceof WorkflowDefinition ? WorkflowDefinitionView::fromDefinition($draft) : null;
    }

    public function versions(string $slug, ?WorkflowTenant $tenant): array
    {
        return array_map(
            static fn (WorkflowDefinition $definition): WorkflowDefinitionView => WorkflowDefinitionView::fromDefinition($definition),
            $this->repository->findVersions($slug, $tenant),
        );
    }

    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->cloneTemplate($slug, $tenant, $publish));
    }

    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->createDraft($slug, $tenant));
    }

    public function createDraftOf(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->createDraftOf($this->resolve($definition)));
    }

    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->publishDraft($slug, $tenant));
    }

    public function publish(WorkflowDefinitionRef $draft): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->publish($this->resolve($draft)));
    }

    public function isLockEnabled(): bool
    {
        return $this->versioning->isLockEnabled();
    }

    public function isStructureLocked(WorkflowDefinitionRef $definition): bool
    {
        return $this->versioning->isStructureLocked($this->resolve($definition));
    }

    public function assertStructureAllowed(WorkflowDefinitionRef $definition): void
    {
        $this->versioning->assertStructureAllowed($this->resolve($definition));
    }

    public function retire(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->retire($this->resolve($definition)));
    }

    public function reinstate(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        return WorkflowDefinitionView::fromDefinition($this->versioning->reinstate($this->resolve($definition)));
    }

    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        return $this->versioning->isRetiredScope($slug, $tenant);
    }

    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        return $this->versioning->forgetSubject($subject, $tenant);
    }

    public function delete(WorkflowDefinitionRef $definition): void
    {
        $this->versioning->delete($this->resolve($definition));
    }

    /**
     * The stored version a reference names: looked up in its scope (slug and tenant, never another tenant's) and then
     * matched on the row id.
     *
     * @throws WorkflowNotFoundException when no version of that scope has this version number and id
     */
    private function resolve(WorkflowDefinitionRef $reference): WorkflowDefinition
    {
        $definition = $this->repository->findVersion($reference->slug, $reference->tenant, $reference->version);
        if (!$definition instanceof WorkflowDefinition || $definition->getId() !== $reference->id) {
            throw WorkflowNotFoundException::forSlug($reference->slug);
        }

        return $definition;
    }
}
