<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use Nowo\WorkflowBundle\Model\WorkflowContext;

/**
 * Thrown when multiple workflow definitions match the same context with equal specificity.
 */
final class WorkflowAmbiguousMatchException extends \RuntimeException
{
    /**
     * @param list<string> $slugs
     */
    public static function forContext(WorkflowContext $context, array $slugs): self
    {
        return new self(sprintf(
            'Ambiguous workflow match for subject "%s" and parameters %s: %s',
            $context->subjectClass ?? '(any)',
            json_encode($context->sortedParameters(), \JSON_THROW_ON_ERROR),
            implode(', ', $slugs),
        ));
    }
}
