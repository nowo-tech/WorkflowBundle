<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowAmbiguousMatchException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

use function count;

/**
 * Resolves the best workflow definition for a runtime context.
 *
 * Matching rules:
 * - All match rules of a definition must match (AND).
 * - Definitions with more rules win over broader ones (specificity).
 * - On equal specificity, higher priority wins.
 * - Definitions without rules act as defaults for their subject class.
 */
final class WorkflowResolver
{
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
    ) {
    }

    public function resolve(WorkflowContext $context): WorkflowDefinition
    {
        $candidates = $this->repository->findEnabledCandidates($context->subjectClass);

        $matches = array_values(array_filter(
            $candidates,
            static fn (WorkflowDefinition $definition): bool => $definition->matchesContext($context),
        ));

        if ($matches === []) {
            throw WorkflowNotFoundException::forContext($context);
        }

        usort($matches, static function (WorkflowDefinition $a, WorkflowDefinition $b): int {
            $specificity = $b->getMatchSpecificity() <=> $a->getMatchSpecificity();
            if ($specificity !== 0) {
                return $specificity;
            }

            return $b->getPriority() <=> $a->getPriority();
        });

        $best        = $matches[0];
        $bestScore   = [$best->getMatchSpecificity(), $best->getPriority()];
        $equallyBest = array_filter(
            $matches,
            static fn (WorkflowDefinition $definition): bool => [
                $definition->getMatchSpecificity(),
                $definition->getPriority(),
            ] === $bestScore,
        );

        if (count($equallyBest) > 1) {
            throw WorkflowAmbiguousMatchException::forContext($context, array_values(array_map(static fn (WorkflowDefinition $d): string => $d->getSlug(), $equallyBest)));
        }

        return $best;
    }

    public function resolveSlug(WorkflowContext $context): string
    {
        return $this->resolve($context)->getSlug();
    }

    public function resolveForSubject(object $subject): WorkflowDefinition
    {
        if ($subject instanceof WorkflowContextAwareInterface) {
            $context = $subject->getWorkflowContext()->withSubjectClass($subject::class);
        } else {
            $context = new WorkflowContext(subjectClass: $subject::class);
        }

        return $this->resolve($context);
    }

    public function resolveSlugForSubject(object $subject): string
    {
        return $this->resolveForSubject($subject)->getSlug();
    }

    /**
     * @return list<WorkflowDefinition>
     */
    public function findMatching(WorkflowContext $context): array
    {
        $candidates = $this->repository->findEnabledCandidates($context->subjectClass);

        return array_values(array_filter(
            $candidates,
            static fn (WorkflowDefinition $definition): bool => $definition->matchesContext($context),
        ));
    }
}
