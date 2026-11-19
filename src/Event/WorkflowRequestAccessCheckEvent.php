<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Event;

use Nowo\WorkflowBundle\Model\WorkflowRequestRef;

use function array_key_exists;
use function array_values;

/**
 * The host's `view` decision on the requests one search found (since 1.21, SearchKit group `workflow_requests`).
 *
 * Dispatched once per search page, with every candidate the SQL filters kept (tenant, archived rows, excluded
 * statuses), at most `search_kit.max_candidates` of them. **Deny by default**: a request is shown only when a listener
 * allowed it and no listener denied it — deny wins whatever the listener order. The event cannot be stopped, so every
 * listener runs. Load what the decision needs in one query for all {@see requests()} (no N+1).
 *
 * The event name is this class (PSR-14 dispatch): `#[AsEventListener]` on an `__invoke(WorkflowRequestAccessCheckEvent)`.
 *
 * Ids only (REQ-ARCH-001 rule 4): no entity, no framework type. Never stored (FrankenPHP worker mode).
 */
final class WorkflowRequestAccessCheckEvent
{
    /** @var array<string, WorkflowRequestRef> */
    private array $requests = [];

    /** @var array<string, bool> id => true allowed, false denied */
    private array $decisions = [];

    /**
     * @param string $subjectClass the configured request class (`search_kit.subject_class`)
     * @param string $userIdentifier the signed-in user of the search
     * @param int|string $tenant the gate's tenant of the search
     * @param list<WorkflowRequestRef> $requests the candidates
     */
    public function __construct(
        public readonly string $subjectClass,
        public readonly string $userIdentifier,
        public readonly int|string $tenant,
        array $requests,
    ) {
        foreach ($requests as $request) {
            $this->requests[$request->id] = $request;
        }
    }

    /**
     * The candidates, in search order.
     *
     * @return list<WorkflowRequestRef>
     */
    public function requests(): array
    {
        return array_values($this->requests);
    }

    /** Shows the request unless a listener denies it. Unknown ids are ignored. */
    public function allow(string $id): void
    {
        if (array_key_exists($id, $this->requests) && ($this->decisions[$id] ?? true)) {
            // @igor-ignore - per-dispatch event object, created for one search and never reused (not a service)
            $this->decisions[$id] = true;
        }
    }

    /** Hides the request; a later allow never undoes it. Unknown ids are ignored. */
    public function deny(string $id): void
    {
        if (array_key_exists($id, $this->requests)) {
            // @igor-ignore - per-dispatch event object, created for one search and never reused (not a service)
            $this->decisions[$id] = false;
        }
    }

    /** true allowed, false denied, null no decision yet (hidden). */
    public function decision(string $id): ?bool
    {
        return $this->decisions[$id] ?? null;
    }

    /** Whether the request is shown: allowed and never denied. */
    public function isVisible(string $id): bool
    {
        return ($this->decisions[$id] ?? false) === true;
    }
}
