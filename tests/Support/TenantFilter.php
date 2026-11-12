<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * A host SQL filter (tenant-style) used to check that the private entity manager mirrors the source's filters.
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$this->hasParameter('slug')) {
            return '';
        }

        return $targetTableAlias . '.slug <> ' . $this->getParameter('slug');
    }
}
