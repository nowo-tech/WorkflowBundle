<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowActorProviderInterface;
use Nowo\WorkflowBundle\Model\WorkflowActorRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Default actor (since 1.15): the user identifier of the current Symfony security token, read per call.
 *
 * @internal
 */
final readonly class SecurityTokenActorProvider implements WorkflowActorProviderInterface
{
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function currentActor(): ?WorkflowActorRef
    {
        $identifier = $this->tokenStorage?->getToken()?->getUserIdentifier();
        if ($identifier === null || $identifier === '') {
            return null;
        }

        try {
            return new WorkflowActorRef($identifier);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
