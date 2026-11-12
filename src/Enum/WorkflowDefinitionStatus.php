<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * Lifecycle of one version of a workflow definition (since 1.15).
 *
 * Without tenancy every definition is `published` and edited in place (the 1.14 behaviour). With tenancy
 * (`nowo_workflow.tenancy.tenant_resolver`) a published version is never edited: the editor works on a `draft` copy
 * (the next version number) and publishing it makes it the version new subjects start with.
 */
enum WorkflowDefinitionStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
}
