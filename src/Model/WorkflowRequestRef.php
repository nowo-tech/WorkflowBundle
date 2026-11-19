<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;

/**
 * One request found by a search, as {@see WorkflowRequestAccessCheckEvent} gives it to the
 * host (since 1.21): its id (the `search_kit.id_field` value, as a string) and its status (the stored
 * place name or enum value of the subject's `status_field`). Ids only: no entity, no title, no body.
 */
final readonly class WorkflowRequestRef
{
    public function __construct(
        public string $id,
        public string $status,
    ) {
    }
}
