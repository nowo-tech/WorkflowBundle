<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowDefinitionCatalogInterface;

/**
 * The outcome of {@see WorkflowDefinitionCatalogInterface::installTemplate()} (since 2.1): the definition the tenant
 * has for the template key, and whether this call created it. A second install answers the existing definition with
 * `created = false`.
 */
final readonly class WorkflowTemplateInstallation
{
    public function __construct(
        public WorkflowDefinitionView $definition,
        public bool $created,
    ) {
    }
}
