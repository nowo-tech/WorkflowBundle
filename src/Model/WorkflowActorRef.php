<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Event\WorkflowTransitionAppliedEvent;

use function preg_match;
use function sprintf;
use function strlen;

/**
 * Opaque identifier of the actor (user, API client, system job) applying a transition (since 1.15).
 *
 * Given to the transition permission checker and carried by {@see WorkflowTransitionAppliedEvent};
 * the bundle never stores it.
 */
final readonly class WorkflowActorRef
{
    public const MAX_LENGTH = 255;

    public string $id;

    /**
     * @throws InvalidArgumentException when the id is empty, longer than 255 bytes or holds a control character
     */
    public function __construct(string $id)
    {
        if ($id === '' || strlen($id) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new InvalidArgumentException(sprintf('A workflow actor reference must be 1 to %d bytes without control characters.', self::MAX_LENGTH));
        }
        $this->id = $id;
    }
}
