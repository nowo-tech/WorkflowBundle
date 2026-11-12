<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowTransitionPermissionCheckerInterface;

/**
 * A transition guard "only someone with permission X in the subject's context" to decide (since 1.15), given to
 * {@see WorkflowTransitionPermissionCheckerInterface}.
 *
 * `permission` is the opaque string an editor configured on the transition (e.g. `leave.approve`); the host resolves
 * it against the subject's own context (organization, team, manager chain), not against global Symfony roles.
 * `actor` is null when nobody is authenticated (a console command or a message without an actor).
 */
final readonly class WorkflowTransitionPermissionCheck
{
    public function __construct(
        public string $permission,
        public string $workflowSlug,
        public string $transition,
        public string $subjectClass,
        public ?WorkflowSubjectRef $subject,
        public ?WorkflowTenant $tenant,
        public ?WorkflowActorRef $actor,
    ) {
    }
}
