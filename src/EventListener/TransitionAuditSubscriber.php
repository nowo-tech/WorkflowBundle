<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use DateTimeImmutable;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Event\WorkflowTransitionAppliedEvent;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Util\SubjectClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Transition;

use function array_values;
use function is_int;
use function is_string;

/**
 * Records a {@see WorkflowTransitionAppliedEvent} for every transition a database workflow completes, whoever applied
 * it (WorkflowApplicator, a workflow from the registry, an automatic transition); {@see AfterCommitDispatcher}
 * dispatches it after the commit (since 1.15, `audit.enabled`).
 *
 * @internal
 */
final readonly class TransitionAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AfterCommitDispatcher $dispatcher,
        private TenancyContext $tenancy,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.completed' => ['onCompleted', -1024]];
    }

    public function onCompleted(CompletedEvent $event): void
    {
        if ($event->getMetadata(DatabaseMetadataStore::BUNDLE_MARKER, null) !== true) {
            return;
        }

        $transition = $event->getTransition();
        if (!$transition instanceof Transition) {
            return;
        }

        $subject   = $event->getSubject();
        $slug      = $event->getWorkflowName();
        $version   = $event->getMetadata(DatabaseMetadataStore::DEFINITION_VERSION, null);
        $owner     = $event->getMetadata(DatabaseMetadataStore::DEFINITION_TENANT, null);
        $tenant    = $this->tenancy->tenantOf($subject, $slug);
        $reference = $this->tenancy->referenceOf($subject, $slug);
        $actor     = $this->tenancy->currentActor();

        $this->dispatcher->record(new WorkflowTransitionAppliedEvent(
            $slug,
            is_int($version) ? $version : 1,
            is_string($owner) && $owner !== '' ? $owner : null,
            $transition->getName(),
            array_values($transition->getFroms()),
            array_values($transition->getTos()),
            SubjectClass::real($subject),
            $reference?->id,
            $actor?->id,
            $tenant?->key,
            ($event->getContext()[WorkflowApplicator::CONTEXT_AUTOMATIC] ?? false) === true,
            new DateTimeImmutable(),
        ));
    }
}
