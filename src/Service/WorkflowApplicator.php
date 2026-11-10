<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Doctrine\ClosedEntityManagerResetter;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Exception\UndefinedTransitionException;
use Symfony\Component\Workflow\Transition;
use Throwable;

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
    ) {
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
        $this->applyWithDefinition($subject, $this->requireDefinition($workflowSlug), $transitionName);
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
        $this->applyWithDefinition($subject, $this->resolver->resolve($context), $transitionName);
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
        $this->applyWithDefinition($subject, $this->resolver->resolveForSubject($subject), $transitionName);
    }

    /**
     * Returns the transition names the subject can take in the workflow of the given slug.
     *
     * @return list<string>
     */
    public function getEnabledTransitions(object $subject, string $workflowSlug): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->requireDefinition($workflowSlug));
    }

    /**
     * Returns the transition names the subject can take in the workflow resolved from the context.
     *
     * @return list<string>
     */
    public function getEnabledTransitionsByContext(object $subject, WorkflowContext $context): array
    {
        $context = $context->withSubjectClass($subject::class);

        return $this->getEnabledTransitionsForDefinition($subject, $this->resolver->resolve($context));
    }

    /**
     * Returns the transition names the subject can take in the workflow resolved from the subject.
     *
     * @return list<string>
     */
    public function getEnabledTransitionsForSubject(object $subject): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->resolver->resolveForSubject($subject));
    }

    /**
     * Returns the current marking of the subject in the workflow of the given slug.
     */
    public function getMarking(object $subject, string $workflowSlug): string
    {
        return $this->getMarkingForDefinition($subject, $this->requireDefinition($workflowSlug));
    }

    /**
     * Returns the current marking of the subject in the workflow resolved from the subject.
     */
    public function getMarkingForSubject(object $subject): string
    {
        return $this->getMarkingForDefinition($subject, $this->resolver->resolveForSubject($subject));
    }

    /**
     * Returns the definition that applies to the subject.
     */
    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        return $this->resolver->resolveForSubject($subject);
    }

    private function applyWithDefinition(object $subject, WorkflowDefinition $definition, string $transitionName): void
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->registry->get($definition->getSlug());

        // No can() pre-check: apply() evaluates the guards itself, so each guard listener runs once per apply.
        try {
            $workflow->apply($subject, $transitionName);
        } catch (NotEnabledTransitionException $exception) {
            throw WorkflowTransitionBlockedException::fromNotEnabled($definition->getSlug(), $exception);
        } catch (UndefinedTransitionException $exception) {
            throw new InvalidArgumentException(sprintf('Transition "%s" is not defined on workflow "%s".', $transitionName, $definition->getSlug()), 0, $exception);
        }

        if (!$this->flushAfterApply) {
            return;
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable $exception) {
            ClosedEntityManagerResetter::resetIfClosed($this->managerRegistry, $this->entityManager);

            throw $exception;
        }
    }

    /** @return list<string> */
    private function getEnabledTransitionsForDefinition(object $subject, WorkflowDefinition $definition): array
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->registry->get($definition->getSlug());

        return array_values(array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $workflow->getEnabledTransitions($subject),
        ));
    }

    private function getMarkingForDefinition(object $subject, WorkflowDefinition $definition): string
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->registry->get($definition->getSlug());
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
