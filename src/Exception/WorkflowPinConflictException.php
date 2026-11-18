<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * A concurrent request pinned the subject at the same moment and its pin cannot be read yet (since 1.15, audit NO-v
 * N2): the transition is not applied with a version that may not be the subject's. Retry the operation (for example
 * in a new transaction); the bundle never proceeds with its own proposed version.
 *
 * Since 1.20 it is a LockConflictExceptionInterface (REQ-RUNTIME-003 rule 6): nowo-tech/transaction-doctrine's conflict
 * responder, and the bundle's own routes, answer it 409 + Retry-After with CONFLICT_001 (no transition was applied).
 */
final class WorkflowPinConflictException extends RuntimeException implements LockConflictExceptionInterface
{
    public static function forSlug(string $workflowSlug): self
    {
        return new self(sprintf('The subject was pinned concurrently on workflow "%s"; retry the operation.', $workflowSlug));
    }
}
