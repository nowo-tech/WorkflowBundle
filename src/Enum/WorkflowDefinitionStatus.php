<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * Lifecycle of one version of a workflow definition (since 1.15).
 *
 * Without tenancy every definition is `published` and edited in place (the 1.14 behaviour). With tenancy
 * (`nowo_workflow.tenancy.tenant_resolver`) a published version is never edited: the editor works on a `draft` copy
 * (the next version number) and publishing it makes it the version new subjects start with.
 *
 * Since 1.16 a tenant clone can be **retired** (`retired`): its published versions stop being picked for new subjects
 * (they run the global template again) while the subjects pinned to them keep running them. Hosts that `match` on this
 * enum must handle the new case.
 */
enum WorkflowDefinitionStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Retired   = 'retired';
}
