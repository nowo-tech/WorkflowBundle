<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Event\WorkflowTransitionAppliedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the audit events (what Beacon forwards to AuditKit) and whether a transaction was open when each arrived.
 */
final class RecordingAuditListener implements EventSubscriberInterface
{
    /** @var list<WorkflowTransitionAppliedEvent> */
    public array $events = [];

    public static function getSubscribedEvents(): array
    {
        return [WorkflowTransitionAppliedEvent::class => 'onApplied'];
    }

    public function onApplied(WorkflowTransitionAppliedEvent $event): void
    {
        $this->events[] = $event;
    }
}
