<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiAccessCheckerInterfaceV1130;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** A host checker as written for 1.13 (reference side of the matrix). */
final readonly class LegacyPredicateCheckerV1130 implements WorkflowUiAccessCheckerInterfaceV1130
{
    public function __construct(private string $predicate, private TokenStorageInterface $tokens)
    {
    }

    public function isGranted(Request $request): bool
    {
        return TokenFacts::decide($this->predicate, $request, $this->tokens);
    }
}
