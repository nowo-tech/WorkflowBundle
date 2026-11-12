<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

use function is_int;

/**
 * Tenancy defence in depth (since 1.15, registered only with tenancy): a database workflow can only move a subject
 * when it is built from the very version {@see WorkflowDefinitionLocator} picks for that subject (its pinned version,
 * else its tenant's, else the global template).
 *
 * So a workflow obtained some other way (`WorkflowRegistryInterface::get()` returns the global template, a workflow
 * kept from another subject, another tenant's definition) cannot move a tenant's subject: every transition is
 * blocked. Runs before every other guard listener.
 *
 * @internal
 */
final readonly class TenantIsolationGuardSubscriber implements EventSubscriberInterface
{
    public const CODE = 'nowo_workflow.tenant_mismatch';

    public function __construct(
        private WorkflowDefinitionLocator $locator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.guard' => ['onGuard', 2048]];
    }

    public function onGuard(GuardEvent $event): void
    {
        if ($event->getMetadata(DatabaseMetadataStore::BUNDLE_MARKER, null) !== true) {
            return;
        }

        // An unsaved definition (id null) never matches: it is blocked.
        $id = $event->getMetadata(DatabaseMetadataStore::DEFINITION_ID, null);
        try {
            $expected = $this->locator->forSubject($event->getSubject(), $event->getWorkflowName())->getId();
        } catch (WorkflowNotFoundException) {
            $expected = null;
        }

        if (!is_int($id) || $expected !== $id) {
            $event->addTransitionBlocker(new TransitionBlocker('This workflow version does not apply to the subject\'s tenant.', self::CODE));
        }
    }
}
