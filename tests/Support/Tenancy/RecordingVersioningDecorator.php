<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionRef;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;

/**
 * A host decorator of the versioning port (the 2.0 "decorate the twin" recipe): records each write and delegates.
 * Hosts calling the port go through it; the admin UI does not (it calls the internal service with the definition it
 * authorised).
 */
final class RecordingVersioningDecorator implements WorkflowVersionManagerInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly WorkflowVersionManagerInterface $inner)
    {
    }

    public function currentVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView
    {
        return $this->inner->currentVersion($slug, $tenant);
    }

    public function draftVersion(string $slug, ?WorkflowTenant $tenant): ?WorkflowDefinitionView
    {
        return $this->inner->draftVersion($slug, $tenant);
    }

    /**
     * @return list<WorkflowDefinitionView>
     */
    public function versions(string $slug, ?WorkflowTenant $tenant): array
    {
        return $this->inner->versions($slug, $tenant);
    }

    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinitionView
    {
        $this->calls[] = 'cloneTemplate';

        return $this->inner->cloneTemplate($slug, $tenant, $publish);
    }

    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView
    {
        $this->calls[] = 'createDraft';

        return $this->inner->createDraft($slug, $tenant);
    }

    public function createDraftOf(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        $this->calls[] = 'createDraftOf';

        return $this->inner->createDraftOf($definition);
    }

    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinitionView
    {
        $this->calls[] = 'publishDraft';

        return $this->inner->publishDraft($slug, $tenant);
    }

    public function publish(WorkflowDefinitionRef $draft): WorkflowDefinitionView
    {
        $this->calls[] = 'publish';

        return $this->inner->publish($draft);
    }

    public function isLockEnabled(): bool
    {
        return $this->inner->isLockEnabled();
    }

    public function isStructureLocked(WorkflowDefinitionRef $definition): bool
    {
        return $this->inner->isStructureLocked($definition);
    }

    public function assertStructureAllowed(WorkflowDefinitionRef $definition): void
    {
        $this->inner->assertStructureAllowed($definition);
    }

    public function retire(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        $this->calls[] = 'retire';

        return $this->inner->retire($definition);
    }

    public function reinstate(WorkflowDefinitionRef $definition): WorkflowDefinitionView
    {
        $this->calls[] = 'reinstate';

        return $this->inner->reinstate($definition);
    }

    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        return $this->inner->isRetiredScope($slug, $tenant);
    }

    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        $this->calls[] = 'forgetSubject';

        return $this->inner->forgetSubject($subject, $tenant);
    }

    public function delete(WorkflowDefinitionRef $definition): void
    {
        $this->calls[] = 'delete';

        $this->inner->delete($definition);
    }
}
