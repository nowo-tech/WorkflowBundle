<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowVersioningInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowVersionManager;

/**
 * Records what {@see WorkflowVersionManager} asks the versioning SPI, and answers with a
 * configured definition (1.19 twin port tests).
 */
final class FakeVersioning implements WorkflowVersioningInterface
{
    /** @var list<string> method names in call order */
    public array $calls = [];

    /** @var list<WorkflowDefinition> the definitions the twin passed, in call order */
    public array $definitions = [];

    /** @var list<array{string, WorkflowTenant|null}> the (slug, tenant) pairs the twin passed, in call order */
    public array $scopes = [];

    public bool $lockEnabled = false;

    public bool $structureLocked = false;

    public bool $retiredScope = false;

    public int $forgotten = 0;

    public ?WorkflowSubjectRef $forgottenSubject = null;

    public function __construct(
        private readonly WorkflowDefinition $answer,
    ) {
    }

    public function cloneTemplate(string $slug, WorkflowTenant $tenant, bool $publish = false): WorkflowDefinition
    {
        $this->calls[]  = 'cloneTemplate' . ($publish ? ':published' : '');
        $this->scopes[] = [$slug, $tenant];

        return $this->answer;
    }

    public function createDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $this->calls[]  = 'createDraft';
        $this->scopes[] = [$slug, $tenant];

        return $this->answer;
    }

    public function createDraftOf(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[]       = 'createDraftOf';
        $this->definitions[] = $definition;

        return $this->answer;
    }

    public function publishDraft(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $this->calls[]  = 'publishDraft';
        $this->scopes[] = [$slug, $tenant];

        return $this->answer;
    }

    public function publish(WorkflowDefinition $draft): WorkflowDefinition
    {
        $this->calls[]       = 'publish';
        $this->definitions[] = $draft;

        return $this->answer;
    }

    public function isLockEnabled(): bool
    {
        $this->calls[] = 'isLockEnabled';

        return $this->lockEnabled;
    }

    public function isStructureLocked(WorkflowDefinition $definition): bool
    {
        $this->calls[]       = 'isStructureLocked';
        $this->definitions[] = $definition;

        return $this->structureLocked;
    }

    public function assertStructureAllowed(WorkflowDefinition $definition): void
    {
        $this->calls[]       = 'assertStructureAllowed';
        $this->definitions[] = $definition;
    }

    public function retire(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[]       = 'retire';
        $this->definitions[] = $definition;

        return $this->answer;
    }

    public function reinstate(WorkflowDefinition $definition): WorkflowDefinition
    {
        $this->calls[]       = 'reinstate';
        $this->definitions[] = $definition;

        return $this->answer;
    }

    public function isRetiredScope(string $slug, ?WorkflowTenant $tenant): bool
    {
        $this->calls[]  = 'isRetiredScope';
        $this->scopes[] = [$slug, $tenant];

        return $this->retiredScope;
    }

    public function forgetSubject(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        $this->calls[]          = 'forgetSubject';
        $this->scopes[]         = ['', $tenant];
        $this->forgottenSubject = $subject;

        return $this->forgotten;
    }

    public function delete(WorkflowDefinition $definition): void
    {
        $this->calls[]       = 'delete';
        $this->definitions[] = $definition;
    }
}
