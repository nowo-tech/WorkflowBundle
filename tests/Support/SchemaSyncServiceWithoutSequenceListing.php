<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Nowo\WorkflowBundle\Service\SchemaSyncService;

/**
 * Test double that simulates schema managers without sequence listing support.
 */
final class SchemaSyncServiceWithoutSequenceListing extends SchemaSyncService
{
    protected function schemaManagerSupportsSequenceListing(object $schemaManager): bool
    {
        return false;
    }
}
