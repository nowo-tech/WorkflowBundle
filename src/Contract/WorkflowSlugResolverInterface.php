<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Exception\WorkflowAmbiguousMatchException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Service\WorkflowResolver;

/**
 * Which workflow a subject or a context runs (since 1.17, REQ-ARCH-001 rule 1): the slug to pass to
 * {@see WorkflowRuntimeInterface}. Scalars and bundle VOs only (no entity, no Symfony or Doctrine type).
 * {@see WorkflowResolver} is the built-in implementation (class distance, rule specificity, priority; with tenancy the
 * subject's tenant version wins over the global template); a host may decorate or replace it by aliasing this
 * interface.
 */
interface WorkflowSlugResolverInterface
{
    /**
     * The slug of the best matching global definition for the context.
     *
     * @throws WorkflowNotFoundException when nothing matches
     * @throws WorkflowAmbiguousMatchException when two definitions tie
     */
    public function resolveSlug(WorkflowContext $context): string;

    /**
     * The slug of the best matching definition for the subject: its own context
     * ({@see WorkflowContextAwareInterface}) and, with tenancy, its tenant.
     *
     * @throws WorkflowNotFoundException when nothing matches
     * @throws WorkflowAmbiguousMatchException when two definitions tie
     */
    public function resolveSlugForSubject(object $subject): string;
}
