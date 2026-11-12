<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;

/**
 * Host port (since 1.15): a stable, opaque id of a workflow subject (`nowo_workflow.tenancy.subject_reference_resolver`).
 *
 * Used to pin the definition version a subject started with (tenancy), in transition permission checks and in the
 * audit event. Return null for a subject without an id yet (not persisted): it is not pinned.
 */
interface WorkflowSubjectReferenceResolverInterface
{
    public function referenceOf(WorkflowSubject $subject): ?WorkflowSubjectRef;
}
