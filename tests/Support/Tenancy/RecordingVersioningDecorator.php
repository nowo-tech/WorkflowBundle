<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;

/**
 * A host decorator of the 1.17 versioning port: records each call and delegates (the admin UI must go through it).
 */
final class RecordingVersioningDecorator implements WorkflowVersioningInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly WorkflowVersioningInterface $inner)
    {
    }

    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinition
    {
        $this->calls[] = 'cloneTemplate';

        return $this->inner->cloneTemplate($slug, $tenant, $publish);
    }

    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $this->calls[] = 'createDraft';

        return $this->inner->createDraft($slug, $tenant);
    }

    public function createDraftOf(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[] = 'createDraftOf';

        return $this->inner->createDraftOf($definition);
    }

    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $this->calls[] = 'publishDraft';

        return $this->inner->publishDraft($slug, $tenant);
    }

    public function publish(WorkflowDefinition $draft): WorkflowDefinition
    {
        $this->calls[] = 'publish';

        return $this->inner->publish($draft);
    }

    public function isLockEnabled(): bool
    {
        $this->calls[] = 'isLockEnabled';

        return $this->inner->isLockEnabled();
    }

    public function isStructureLocked(WorkflowDefinition $definition): bool
    {
        $this->calls[] = 'isStructureLocked';

        return $this->inner->isStructureLocked($definition);
    }

    public function assertStructureAllowed(WorkflowDefinition $definition): void
    {
        $this->calls[] = 'assertStructureAllowed';

        $this->inner->assertStructureAllowed($definition);
    }

    public function retire(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[] = 'retire';

        return $this->inner->retire($definition);
    }

    public function reinstate(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[] = 'reinstate';

        return $this->inner->reinstate($definition);
    }

    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        $this->calls[] = 'isRetiredScope';

        return $this->inner->isRetiredScope($slug, $tenant);
    }

    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        $this->calls[] = 'forgetSubject';

        return $this->inner->forgetSubject($subject, $tenant);
    }

    public function delete(WorkflowDefinition $definition): void
    {
        $this->calls[] = 'delete';

        $this->inner->delete($definition);
    }
}
