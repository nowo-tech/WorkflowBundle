<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowSlugResolverInterface;
use Nowo\WorkflowBundle\Model\WorkflowContext;

/**
 * A host's own slug resolver (it wins over the built-in one): always the same slug.
 */
final class FixedSlugResolver implements WorkflowSlugResolverInterface
{
    public function resolveSlug(WorkflowContext $context): string
    {
        return 'host-slug';
    }

    public function resolveSlugForSubject(object $subject): string
    {
        return 'host-slug';
    }
}
