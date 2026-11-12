<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;

/**
 * What a Workflow UI request does with the definitions of a tenant scope (since 1.15), asked to the host's
 * {@see WorkflowUiActionCheckerInterface}.
 *
 * - `view`: dashboard, list, detail and diagram;
 * - `edit`: create a definition, edit a draft (or, without tenancy, a definition), create a draft of a published
 *   version, clone a global template into the tenant;
 * - `publish`: publish a draft, delete a definition.
 */
enum WorkflowUiAction: string
{
    case View    = 'view';
    case Edit    = 'edit';
    case Publish = 'publish';
}
