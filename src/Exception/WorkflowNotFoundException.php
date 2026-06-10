<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

/**
 * Thrown when a workflow slug cannot be resolved from the database.
 */
final class WorkflowNotFoundException extends \RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Workflow definition "%s" was not found or is disabled.', $slug));
    }

    public static function forContext(\Nowo\WorkflowBundle\Model\WorkflowContext $context): self
    {
        return new self(sprintf(
            'No enabled workflow definition matches subject "%s" with parameters %s.',
            $context->subjectClass ?? '(any)',
            json_encode($context->sortedParameters(), \JSON_THROW_ON_ERROR),
        ));
    }
}
