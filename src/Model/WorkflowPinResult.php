<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;

/**
 * Outcome of {@see WorkflowRuntimeInterface::pin()} (since 1.16): the definition version
 * the subject is pinned to now.
 *
 * - `created`: this call wrote the pin (false when the subject was pinned already, also by a concurrent request);
 * - `replaced`: this call moved an existing pin to another version (`WorkflowPinMode::Repin`);
 *   `previousDefinitionVersion` is the version it was pinned to before.
 */
final readonly class WorkflowPinResult
{
    /**
     * @param string|null $definitionTenant tenant key of the pinned version (null = global template)
     */
    public function __construct(
        public string $workflowSlug,
        public int $definitionVersion,
        public ?string $definitionTenant,
        public WorkflowSubjectRef $subject,
        public bool $created,
        public bool $replaced = false,
        public ?int $previousDefinitionVersion = null,
    ) {
    }
}
