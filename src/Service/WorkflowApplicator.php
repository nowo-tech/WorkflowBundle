<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

/**
 * Applies database-backed workflow transitions to domain subjects.
 */
final class WorkflowApplicator
{
    public function __construct(
        private readonly DatabaseWorkflowRegistry $registry,
        private readonly WorkflowDefinitionRepository $definitionRepository,
        private readonly WorkflowResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WorkflowNotFoundException
     */
    public function apply(object $subject, string $workflowSlug, string $transitionName): void
    {
        $this->applyWithDefinition($subject, $this->requireDefinition($workflowSlug), $transitionName);
    }

    /**
     * @throws WorkflowNotFoundException
     */
    public function applyByContext(object $subject, WorkflowContext $context, string $transitionName): void
    {
        $context = $context->withSubjectClass($subject::class);
        $this->applyWithDefinition($subject, $this->resolver->resolve($context), $transitionName);
    }

    /**
     * @throws WorkflowNotFoundException
     */
    public function applyForSubject(object $subject, string $transitionName): void
    {
        $this->applyWithDefinition($subject, $this->resolver->resolveForSubject($subject), $transitionName);
    }

    /** @return list<string> */
    public function getEnabledTransitions(object $subject, string $workflowSlug): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->requireDefinition($workflowSlug));
    }

    /** @return list<string> */
    public function getEnabledTransitionsByContext(object $subject, WorkflowContext $context): array
    {
        $context = $context->withSubjectClass($subject::class);

        return $this->getEnabledTransitionsForDefinition($subject, $this->resolver->resolve($context));
    }

    /** @return list<string> */
    public function getEnabledTransitionsForSubject(object $subject): array
    {
        return $this->getEnabledTransitionsForDefinition($subject, $this->resolver->resolveForSubject($subject));
    }

    public function getMarking(object $subject, string $workflowSlug): string
    {
        return $this->getMarkingForDefinition($subject, $this->requireDefinition($workflowSlug));
    }

    public function getMarkingForSubject(object $subject): string
    {
        return $this->getMarkingForDefinition($subject, $this->resolver->resolveForSubject($subject));
    }

    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        return $this->resolver->resolveForSubject($subject);
    }

    private function applyWithDefinition(object $subject, WorkflowDefinition $definition, string $transitionName): void
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->registry->get($definition->getSlug());

        if (!$workflow->can($subject, $transitionName)) {
            throw new \InvalidArgumentException(sprintf(
                'Transition "%s" is not enabled for the current marking on workflow "%s".',
                $transitionName,
                $definition->getSlug(),
            ));
        }

        $workflow->apply($subject, $transitionName);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function getEnabledTransitionsForDefinition(object $subject, WorkflowDefinition $definition): array
    {
        $this->assertSubjectSupported($subject, $definition);
        $workflow = $this->registry->get($definition->getSlug());

        return array_values(array_map(
            static fn ($transition): string => $transition->getName(),
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

        if ($definition === null || !$definition->isEnabled()) {
            throw WorkflowNotFoundException::forSlug($workflowSlug);
        }

        return $definition;
    }

    private function assertSubjectSupported(object $subject, WorkflowDefinition $definition): void
    {
        if (!is_a($subject, $definition->getSubjectClass())) {
            throw new \InvalidArgumentException(sprintf(
                'Subject of type "%s" is not supported by workflow "%s" (expects "%s").',
                $subject::class,
                $definition->getSlug(),
                $definition->getSubjectClass(),
            ));
        }
    }
}
