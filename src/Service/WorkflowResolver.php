<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowAmbiguousMatchException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Util\SubjectClass;

use function count;

use const PHP_INT_MIN;

/**
 * Resolves the best workflow definition for a runtime context.
 *
 * Matching rules:
 * - The definition subject_class must be the subject class or one of its parents/interfaces
 *   (Doctrine proxies resolve to their real class); the closest class wins first.
 * - All match rules of a definition must match (AND).
 * - Definitions with more rules win over broader ones (specificity).
 * - On equal specificity, higher priority wins.
 * - Definitions without rules act as defaults for their subject class.
 */
final class WorkflowResolver
{
    /**
     * Creates the resolver with the definition repository.
     */
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
    ) {
    }

    /**
     * Returns the best matching enabled definition (class distance, specificity, priority).
     */
    public function resolve(WorkflowContext $context): WorkflowDefinition
    {
        $hierarchy = $context->subjectClass !== null ? SubjectClass::hierarchy($context->subjectClass) : [];
        $matches   = $this->findMatching($context);

        if ($matches === []) {
            throw WorkflowNotFoundException::forContext($context);
        }

        $score = static function (WorkflowDefinition $definition) use ($hierarchy): array {
            // Closest class in the subject hierarchy first (exact class beats parent class / interface).
            $distance = array_search($definition->getSubjectClass(), $hierarchy, true);

            return [
                $distance === false ? PHP_INT_MIN : -$distance,
                $definition->getMatchSpecificity(),
                $definition->getPriority(),
            ];
        };

        usort($matches, static fn (WorkflowDefinition $a, WorkflowDefinition $b): int => $score($b) <=> $score($a));

        $best        = $matches[0];
        $bestScore   = $score($best);
        $equallyBest = array_filter(
            $matches,
            static fn (WorkflowDefinition $definition): bool => $score($definition) === $bestScore,
        );

        if (count($equallyBest) > 1) {
            throw WorkflowAmbiguousMatchException::forContext($context, array_values(array_map(static fn (WorkflowDefinition $d): string => $d->getSlug(), $equallyBest)));
        }

        return $best;
    }

    /**
     * Returns the slug of the best matching definition.
     */
    public function resolveSlug(WorkflowContext $context): string
    {
        return $this->resolve($context)->getSlug();
    }

    /**
     * Returns the best matching definition for a subject and its own context.
     */
    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        if ($subject instanceof WorkflowContextAwareInterface) {
            $context = $subject->getWorkflowContext()->withSubjectClass(SubjectClass::real($subject));
        } else {
            $context = new WorkflowContext(subjectClass: SubjectClass::real($subject));
        }

        return $this->resolve($context);
    }

    /**
     * Returns the slug of the best matching definition for a subject.
     */
    public function resolveSlugForSubject(object $subject): string
    {
        return $this->resolveForSubject($subject)->getSlug();
    }

    /**
     * Returns the enabled definitions whose subject class and rules match the context.
     *
     * @return list<WorkflowDefinition>
     */
    public function findMatching(WorkflowContext $context): array
    {
        $candidates = $this->repository->findEnabledCandidates(
            $context->subjectClass !== null ? SubjectClass::hierarchy($context->subjectClass) : null,
        );

        return array_values(array_filter(
            $candidates,
            static fn (WorkflowDefinition $definition): bool => $definition->matchesContext($context),
        ));
    }
}
