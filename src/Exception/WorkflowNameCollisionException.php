<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use LogicException;

use function sprintf;

/**
 * Thrown when a database workflow slug equals the name of a workflow configured under framework.workflows.
 *
 * Both would dispatch the same workflow.<name>.* events, so the listeners and guard expressions of the framework
 * workflow would receive the subjects of the database workflow (and the other way round).
 */
final class WorkflowNameCollisionException extends LogicException
{
    /**
     * Creates the exception for a slug that is also a framework workflow name.
     */
    public static function forSlug(string $slug): self
    {
        return new self(sprintf(
            'The database workflow slug "%s" is also the name of a workflow configured under framework.workflows. Both would dispatch the same "workflow.%s.*" events; rename the slug of the database workflow.',
            $slug,
            $slug,
        ));
    }
}
