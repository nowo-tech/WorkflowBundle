<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Event;

use DateTimeImmutable;

/**
 * A transition of a database workflow was applied and its transaction committed (since 1.15, REQ-ARCH-001 rules 4
 * and 7): ids only, no entity, no framework type. Forward it to an audit log.
 *
 * Dispatched after the commit of the transaction that persisted the subject (see docs/MULTITENANCY.md, "Audit"):
 * never for a rolled-back transaction or a marking change that was never persisted. Enabled with
 * `nowo_workflow.audit.enabled` (default: on with tenancy).
 */
final readonly class WorkflowTransitionAppliedEvent
{
    /**
     * @param list<string> $from marking places before the transition
     * @param list<string> $to marking places after the transition
     * @param string|null $tenant subject's tenant key (null without tenancy or for a subject without a tenant)
     * @param string|null $definitionTenant tenant key of the definition version that ran (null = global template)
     * @param bool $automatic applied by an automatic transition
     */
    public function __construct(
        public string $workflowSlug,
        public int $definitionVersion,
        public ?string $definitionTenant,
        public string $transition,
        public array $from,
        public array $to,
        public string $subjectClass,
        public ?string $subjectRef,
        public ?string $actorRef,
        public ?string $tenant,
        public bool $automatic,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
