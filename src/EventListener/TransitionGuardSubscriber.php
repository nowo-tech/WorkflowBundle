<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectVariablesProviderInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTransitionPermissionCheckerInterface;
use Nowo\WorkflowBundle\Expression\GuardExpression;
use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowTransitionPermissionCheck;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Util\SubjectClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

use function is_string;

/**
 * Enforces the guards an editor configured on a transition of a database workflow (since 1.15):
 *
 * - **permission**: {@see WorkflowTransitionPermissionCheckerInterface} decides in the subject's context;
 * - **condition**: a {@see GuardExpression} over the host's whitelisted subject variables
 *   ({@see WorkflowSubjectVariablesProviderInterface}).
 *
 * Fail closed: a guard whose port is not configured, a condition that is false or cannot be evaluated, and a port
 * that throws all block the transition. Transitions without guards (every 1.14 transition) are untouched.
 *
 * @internal
 */
final readonly class TransitionGuardSubscriber implements EventSubscriberInterface
{
    public const CODE_PERMISSION = 'nowo_workflow.permission_denied';

    public const CODE_CONDITION = 'nowo_workflow.condition_not_met';

    public function __construct(
        private TenancyContext $tenancy,
        private ?WorkflowTransitionPermissionCheckerInterface $permissionChecker = null,
        private ?WorkflowSubjectVariablesProviderInterface $variablesProvider = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.guard' => ['onGuard', 0]];
    }

    public function onGuard(GuardEvent $event): void
    {
        // Event::getMetadata() (Symfony 7.4 and 8; Event::getWorkflow() is gone in 8).
        if ($event->getMetadata(DatabaseMetadataStore::BUNDLE_MARKER, null) !== true) {
            return;
        }

        $transition = $event->getTransition();
        $permission = $event->getMetadata(DatabaseMetadataStore::GUARD_PERMISSION, $transition);
        $condition  = $event->getMetadata(DatabaseMetadataStore::GUARD_EXPRESSION, $transition);

        if (is_string($permission) && $permission !== '' && !$this->permissionGranted($event, $permission)) {
            $event->addTransitionBlocker(new TransitionBlocker('The actor lacks the permission this transition requires.', self::CODE_PERMISSION));
        }

        if (is_string($condition) && $condition !== '' && !$this->conditionMet($event, $condition)) {
            $event->addTransitionBlocker(new TransitionBlocker('The condition of this transition is not met.', self::CODE_CONDITION));
        }
    }

    private function permissionGranted(GuardEvent $event, string $permission): bool
    {
        if (!$this->permissionChecker instanceof WorkflowTransitionPermissionCheckerInterface) {
            return false;
        }

        $subject = $event->getSubject();
        $slug    = $event->getWorkflowName();

        return $this->permissionChecker->isGranted(new WorkflowTransitionPermissionCheck(
            $permission,
            $slug,
            $event->getTransition()->getName(),
            SubjectClass::real($subject),
            $this->tenancy->referenceOf($subject, $slug),
            $this->tenancy->tenantOf($subject, $slug),
            $this->tenancy->currentActor(),
        ));
    }

    private function conditionMet(GuardEvent $event, string $condition): bool
    {
        if (!$this->variablesProvider instanceof WorkflowSubjectVariablesProviderInterface) {
            return false;
        }

        try {
            return GuardExpression::evaluate($condition, $this->variablesProvider->variablesOf(new WorkflowSubject($event->getSubject(), $event->getWorkflowName())));
        } catch (InvalidArgumentException $exception) {
            // GuardExpressionException or invalid variables from the host: blocked, logged without values.
            $this->logger?->warning('Workflow transition condition could not be evaluated; the transition is blocked.', [
                'bundle'     => 'NowoWorkflowBundle',
                'workflow'   => $event->getWorkflowName(),
                'transition' => $event->getTransition()->getName(),
                'error'      => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
