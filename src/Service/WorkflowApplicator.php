<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Doctrine\ClosedEntityManagerResetter;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Exception\UndefinedTransitionException;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Throwable;

use function array_keys;
use function implode;
use function sort;
use function sprintf;

/**
 * Applies database-backed workflow transitions to domain subjects.
 *
 * The Symfony workflow events (leave, transition, enter, entered, completed, announce) are dispatched inside
 * WorkflowInterface::apply(), so their listeners run before the flush of this applicator: do not publish anything
 * that must only happen once the transition is persisted from a completed / announce listener.
 */
final class WorkflowApplicator
{
    /** Workflow context flag of a transition applied automatically (since 1.15). */
    public const CONTEXT_AUTOMATIC = 'nowo_workflow_automatic';

    /**
     * Creates the applicator with the registry, repository, resolver and entity manager.
     *
     * With $flushAfterApply false (config nowo_workflow.runtime.flush_after_apply) apply*() only changes the marking
     * of the subject and leaves persisting it to the caller.
     */
    public function __construct(
        private readonly WorkflowRegistryInterface $registry,
        private readonly WorkflowDefinitionRepository $definitionRepository,
        private readonly WorkflowResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly bool $flushAfterApply = true,
        private readonly ?WorkflowDefinitionLocator $locator = null,
        private readonly ?DatabaseWorkflowRegistry $databaseRegistry = null,
        private readonly ?AfterCommitDispatcher $audit = null,
        private readonly int $maxAutomaticTransitions = 10,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Applies the automatic transitions the subject can take now, one after the other (since 1.15), and flushes when
     * one was applied (unless runtime.flush_after_apply is false). Idempotent: a subject with no enabled automatic
     * transition is left untouched. Loop-safe: it stops when a marking repeats or after
     * `guards.max_automatic_transitions` steps.
     *
     * Call it after creating a subject whose initial place has an automatic transition; apply*() runs it already.
     *
     * @return list<string> the transitions applied, in order
     */
    public function applyAutomaticTransitions(object $subject, ?string $workflowSlug = null): array
    {
        $definition = $workflowSlug !== null ? $this->definitionFor($subject, $workflowSlug) : $this->resolveDefinitionForSubject($subject);
        $this->assertSubjectSupported($subject, $definition);
        $applied = $this->runAutomatic($definition, $this->workflowFor($definition), $subject);
        if ($applied === []) {
            return [];
        }
        $this->locator?->pin($subject, $definition);
        $this->flushIfConfigured();

        return $applied;
    }

    /**
     * Applies a transition with the workflow of the given slug and flushes (unless runtime.flush_after_apply is false).
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowTransitionBlockedException when the marking or a guard listener blocks the transition
     * @throws InvalidArgumentException when the subject is not supported or the transition is not defined
     */
    public function apply(object $subject, string $workflowSlug, string $transitionName): void
    {
        $this->applyWithDefinition($subject, $this->definitionFor($subject, $workflowSlug), $transitionName);
    }

    /**
     * Same as {@see apply()}, returning the automatic transitions applied after it, in order (since 1.15).
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowTransitionBlockedException when the marking or a guard listener blocks the transition
     * @throws InvalidArgumentException when the subject is not supported or the transition is not defined
     *
     * @return list<string>
     */
    public function applyWithAutomatic(object $subject, string $workflowSlug, string $transitionName): array
    {
        return $this->applyWithDefinition($subject, $this->definitionFor($subject, $workflowSlug), $transitionName);
    }

    /**
     * Whether the subject can take the transition now in the workflow of the slug (guards evaluated), since 1.15.
     */
    public function can(object $subject, string $workflowSlug, string $transitionName): bool
    {
        $definition = $this->definitionFor($subject, $workflowSlug);
        $this->assertSubjectSupported($subject, $definition);

        return $this->workflowFor($definition)->can($subject, $transitionName);
    }

    /**
     * The subject's marking in the workflow of the slug, with the definition version that runs (since 1.15).
     */
    public function markingView(object $subject, string $workflowSlug): WorkflowMarkingView
    {
        $definition = $this->definitionFor($subject, $workflowSlug);
        $this->assertSubjectSupported($subject, $definition);

        return new WorkflowMarkingView(
            $definition->getSlug(),
            $definition->getVersion(),
            $definition->getTenantKey() === '' ? null : $definition->getTenantKey(),
            array_values(array_map('strval', array_keys($this->workflowFor($definition)->getMarking($subject)->getPlaces()))),
        );
    }

    /**
     * Applies a transition with the workflow resolved from the context and flushes (unless runtime.flush_after_apply is false).
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowTransitionBlockedException when the marking or a guard listener blocks the transition
     * @throws InvalidArgumentException when the subject is not supported or the transition is not defined
     */
    public function applyByContext(object $subject, WorkflowContext $context, string $transitionName): void
    {
        $context = $context->withSubjectClass($subject::class);
        $this->applyWithDefinition($subject, $this->resolveDefinitionForContext($subject, $context), $transitionName);
    }

    /**
     * Applies a transition with the workflow resolved from the subject and flushes (unless runtime.flush_after_apply is false).
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowTransitionBlockedException when the marking or a guard listener blocks the transition
     * @throws InvalidArgumentException when the subject is not supported or the transition is not defined
     */
    public function applyForSubject(object $subject, string $transitionName): void
    {
        $this->applyWithDefinition($subject, $this->resolveDefinitionForSubject($subject), $transitionName);
    }

    /**
     * Returns the transition names the subject can take in the workflow of the given slug.
     *
     * @return list<string>
     */
    public function getEnabledTransitions(object $subject, string $workflowSlug): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->definitionFor($subject, $workflowSlug));
    }

    /**
     * Returns the transition names the subject can take in the workflow resolved from the context.
     *
     * @return list<string>
     */
    public function getEnabledTransitionsByContext(object $subject, WorkflowContext $context): array
    {
        $context = $context->withSubjectClass($subject::class);

        return $this->getEnabledTransitionsForDefinition($subject, $this->resolveDefinitionForContext($subject, $context));
    }

    /**
     * Returns the transition names the subject can take in the workflow resolved from the subject.
     *
     * @return list<string>
     */
    public function getEnabledTransitionsForSubject(object $subject): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->resolveDefinitionForSubject($subject));
    }

    /**
     * Returns the current marking of the subject in the workflow of the given slug.
     */
    public function getMarking(object $subject, string $workflowSlug): string
    {
        return $this->getMarkingForDefinition($subject, $this->definitionFor($subject, $workflowSlug));
    }

    /**
     * Returns the current marking of the subject in the workflow resolved from the subject.
     */
    public function getMarkingForSubject(object $subject): string
    {
        return $this->getMarkingForDefinition($subject, $this->resolveDefinitionForSubject($subject));
    }

    /**
     * Returns the definition that applies to the subject (with tenancy: its pinned version, else its tenant's, else
     * the global template).
     */
    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        return $this->resolveDefinitionForSubject($subject);
    }

    /**
     * @return list<string> automatic transitions applied after it
     */
    private function applyWithDefinition(object $subject, WorkflowDefinition $definition, string $transitionName): array
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->workflowFor($definition);

        // No can() pre-check: apply() evaluates the guards itself, so each guard listener runs once per apply.
        try {
            $workflow->apply($subject, $transitionName);
        } catch (NotEnabledTransitionException $exception) {
            throw WorkflowTransitionBlockedException::fromNotEnabled($definition->getSlug(), $exception);
        } catch (UndefinedTransitionException $exception) {
            throw new InvalidArgumentException(sprintf('Transition "%s" is not defined on workflow "%s".', $transitionName, $definition->getSlug()), 0, $exception);
        }

        $automatic = $this->runAutomatic($definition, $workflow, $subject);
        $this->locator?->pin($subject, $definition);
        $this->flushIfConfigured();

        return $automatic;
    }

    private function flushIfConfigured(): void
    {
        if (!$this->flushAfterApply) {
            return;
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable $exception) {
            ClosedEntityManagerResetter::resetIfClosed($this->managerRegistry, $this->entityManager);

            throw $exception;
        }
        // The flush committed: the audit events it persisted can go out now.
        $this->audit?->dispatchPending();
    }

    /**
     * Applies enabled automatic transitions until none is left, a marking repeats or the step limit is reached.
     *
     * @return list<string>
     */
    private function runAutomatic(WorkflowDefinition $definition, WorkflowInterface $workflow, object $subject): array
    {
        $automatic = [];
        foreach ($definition->getTransitions() as $transition) {
            if ($transition->isAutomatic()) {
                $automatic[] = $transition->getName();
            }
        }
        // No automatic transition (every 1.14 definition): no extra guard evaluation at all.
        if ($automatic === []) {
            return [];
        }
        sort($automatic);

        $applied = [];
        $seen    = [$this->markingKey($workflow, $subject) => true];
        for ($step = 0; $step < $this->maxAutomaticTransitions; ++$step) {
            $next = null;
            foreach ($automatic as $name) {
                if ($workflow->can($subject, $name)) {
                    $next = $name;

                    break;
                }
            }
            if ($next === null) {
                return $applied;
            }

            try {
                $workflow->apply($subject, $next, [self::CONTEXT_AUTOMATIC => true]);
            } catch (NotEnabledTransitionException) {
                // A guard changed its mind between the two checks: stop, nothing applied.
                return $applied;
            }
            $applied[] = $next;

            $key = $this->markingKey($workflow, $subject);
            if (isset($seen[$key])) {
                $this->logger?->warning('Workflow "{workflow}": automatic transitions loop back to a marking already reached; stopped.', ['bundle' => 'NowoWorkflowBundle', 'workflow' => $workflow->getName(), 'transitions' => $applied]);

                return $applied;
            }
            $seen[$key] = true;
        }

        if ($this->maxAutomaticTransitions > 0) {
            $this->logger?->warning('Workflow "{workflow}": stopped after {count} automatic transitions (guards.max_automatic_transitions).', ['bundle' => 'NowoWorkflowBundle', 'workflow' => $workflow->getName(), 'count' => $this->maxAutomaticTransitions]);
        }

        return $applied;
    }

    private function markingKey(WorkflowInterface $workflow, object $subject): string
    {
        $places = array_keys($workflow->getMarking($subject)->getPlaces());
        sort($places);

        return implode("\0", $places);
    }

    private function workflowFor(WorkflowDefinition $definition): WorkflowInterface
    {
        if ($this->locator?->tenancyEnabled() === true && $this->databaseRegistry instanceof DatabaseWorkflowRegistry) {
            return $this->databaseRegistry->forDefinition($definition);
        }

        return $this->registry->get($definition->getSlug());
    }

    private function definitionFor(object $subject, string $workflowSlug): WorkflowDefinition
    {
        return $this->locator instanceof WorkflowDefinitionLocator ? $this->locator->forSubject($subject, $workflowSlug) : $this->requireDefinition($workflowSlug);
    }

    private function resolveDefinitionForSubject(object $subject): WorkflowDefinition
    {
        return $this->locator instanceof WorkflowDefinitionLocator ? $this->locator->resolveForSubject($subject) : $this->resolver->resolveForSubject($subject);
    }

    private function resolveDefinitionForContext(object $subject, WorkflowContext $context): WorkflowDefinition
    {
        return $this->locator instanceof WorkflowDefinitionLocator ? $this->locator->resolveForContext($subject, $context) : $this->resolver->resolve($context);
    }

    /** @return list<string> */
    private function getEnabledTransitionsForDefinition(object $subject, WorkflowDefinition $definition): array
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->workflowFor($definition);

        return array_values(array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $workflow->getEnabledTransitions($subject),
        ));
    }

    private function getMarkingForDefinition(object $subject, WorkflowDefinition $definition): string
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->workflowFor($definition);
        $marking  = $workflow->getMarking($subject);

        if ($marking->getPlaces() === []) {
            return '';
        }

        $places = array_keys($marking->getPlaces());

        return (string) ($places[0] ?? '');
    }

    private function requireDefinition(string $workflowSlug): WorkflowDefinition
    {
        $definition = $this->definitionRepository->findOneBySlug($workflowSlug);

        if (!$definition instanceof WorkflowDefinition || !$definition->isEnabled()) {
            throw WorkflowNotFoundException::forSlug($workflowSlug);
        }

        return $definition;
    }

    private function assertSubjectSupported(object $subject, WorkflowDefinition $definition): void
    {
        if (!is_a($subject, $definition->getSubjectClass())) {
            throw new InvalidArgumentException(sprintf('Subject of type "%s" is not supported by workflow "%s" (expects "%s").', $subject::class, $definition->getSlug(), $definition->getSubjectClass()));
        }
    }
}
