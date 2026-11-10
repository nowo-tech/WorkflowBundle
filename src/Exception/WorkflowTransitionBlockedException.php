<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use InvalidArgumentException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\TransitionBlockerList;
use Throwable;

use function sprintf;

/**
 * Thrown by WorkflowApplicator::apply*() when a transition exists but cannot be applied: the marking does not enable
 * it, or a guard listener blocked it. Extends InvalidArgumentException, so callers catching that keep working.
 *
 * The blocker messages (e.g. the reason passed to GuardEvent::setBlocked()) are part of the message and available
 * through getBlockerMessages() / getBlockerCodes() (plain strings) or getTransitionBlockerList().
 */
final class WorkflowTransitionBlockedException extends InvalidArgumentException
{
    /**
     * Creates the exception for a blocked transition with its blocker list.
     */
    public function __construct(
        private readonly string $workflowSlug,
        private readonly string $transitionName,
        private readonly TransitionBlockerList $transitionBlockerList,
        ?Throwable $previous = null,
    ) {
        $messages = $this->messagesOf($transitionBlockerList);

        parent::__construct(sprintf(
            'Transition "%s" is not enabled on workflow "%s"%s',
            $transitionName,
            $workflowSlug,
            $messages === [] ? '.' : ': ' . implode(' ', $messages),
        ), 0, $previous);
    }

    /**
     * Wraps the Symfony exception thrown by Workflow::apply() for a blocked transition.
     */
    public static function fromNotEnabled(string $workflowSlug, NotEnabledTransitionException $exception): self
    {
        return new self($workflowSlug, $exception->getTransitionName(), $exception->getTransitionBlockerList(), $exception);
    }

    /**
     * Returns the slug of the workflow the transition was applied with.
     */
    public function getWorkflowSlug(): string
    {
        return $this->workflowSlug;
    }

    /**
     * Returns the name of the blocked transition.
     */
    public function getTransitionName(): string
    {
        return $this->transitionName;
    }

    /**
     * Returns the Symfony blocker list (marking blocker, guard blockers).
     */
    public function getTransitionBlockerList(): TransitionBlockerList
    {
        return $this->transitionBlockerList;
    }

    /**
     * Returns the blocker messages, e.g. the reason a guard listener passed to setBlocked().
     *
     * @return list<string>
     */
    public function getBlockerMessages(): array
    {
        return $this->messagesOf($this->transitionBlockerList);
    }

    /**
     * Returns the blocker codes (TransitionBlocker::BLOCKED_BY_MARKING, BLOCKED_BY_EXPRESSION_GUARD_LISTENER, …).
     *
     * @return list<string>
     */
    public function getBlockerCodes(): array
    {
        $codes = [];
        foreach ($this->transitionBlockerList as $blocker) {
            /* @var TransitionBlocker $blocker */
            $codes[] = $blocker->getCode();
        }

        return $codes;
    }

    /** @return list<string> */
    private function messagesOf(TransitionBlockerList $list): array
    {
        $messages = [];
        foreach ($list as $blocker) {
            /* @var TransitionBlocker $blocker */
            $messages[] = $blocker->getMessage();
        }

        return $messages;
    }
}
