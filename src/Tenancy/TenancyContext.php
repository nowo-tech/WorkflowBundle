<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowActorProviderInterface;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectReferenceResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTenantResolverInterface;
use Nowo\WorkflowBundle\Model\WorkflowActorRef;
use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;

/**
 * The host's runtime tenancy ports in one place (since 1.15). Stateless: every answer is asked to the host per call
 * (FrankenPHP worker mode, kernel reset false: nothing about a subject, tenant or actor is kept).
 *
 * Tenancy is on only when a tenant resolver is configured; without it every method answers as 1.14 did (no tenant).
 *
 * @internal
 */
final readonly class TenancyContext
{
    public function __construct(
        private ?WorkflowTenantResolverInterface $tenantResolver = null,
        private ?WorkflowSubjectReferenceResolverInterface $referenceResolver = null,
        private ?WorkflowActorProviderInterface $actorProvider = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->tenantResolver instanceof WorkflowTenantResolverInterface;
    }

    /**
     * The subject's tenant (null without tenancy or for a subject without a tenant).
     */
    public function tenantOf(object $subject, string $workflowSlug = ''): ?WorkflowTenant
    {
        return $this->tenantResolver?->tenantOf(new WorkflowSubject($subject, $workflowSlug));
    }

    public function referenceOf(object $subject, string $workflowSlug = ''): ?WorkflowSubjectRef
    {
        return $this->referenceResolver?->referenceOf(new WorkflowSubject($subject, $workflowSlug));
    }

    public function currentActor(): ?WorkflowActorRef
    {
        return $this->actorProvider?->currentActor();
    }
}
