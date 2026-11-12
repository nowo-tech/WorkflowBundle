<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** A host checker on the deprecated port only (unchanged 1.13 code). */
final readonly class LegacyPredicateChecker implements WorkflowUiAccessCheckerInterface
{
    public function __construct(private string $predicate, private TokenStorageInterface $tokens)
    {
    }

    public function isGranted(Request $request): bool
    {
        return TokenFacts::decide($this->predicate, $request, $this->tokens);
    }
}
