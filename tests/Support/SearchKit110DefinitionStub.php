<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

/**
 * Stands for SearchKit 1.10's EntitySearchDefinition in the extension's guard test: it has prepareItems().
 */
final class SearchKit110DefinitionStub
{
    public function prepareItems(): string
    {
        return 'prepared';
    }
}
