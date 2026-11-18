<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Exception;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Throwable;

/**
 * A transition apply lost a race with a concurrent write on the same subject (since 1.20, REQ-RUNTIME-003): the
 * subject's marking changed after this request loaded it, the versioned UPDATE found a newer version, or the row lock
 * met a deadlock or a lock wait timeout. Nothing of the apply was committed, so a retry (after reloading the subject)
 * is safe: HTTP answers 409 + `Retry-After` with the code {@see self::CODE}.
 *
 * It is a TransientStorageFailure and a LockConflictExceptionInterface, so a host using nowo-tech/transaction-doctrine's
 * conflict responder answers it on its own routes without any mapping. The message is constant and client-safe; the
 * storage error, if any, is the previous exception.
 */
final class WorkflowConflictException extends TransientStorageFailure implements LockConflictExceptionInterface
{
    /** Platform conflict code: nothing was committed, a retry is safe (REQ-RUNTIME-003 rule 6). */
    public const CODE = 'CONFLICT_001';

    public const MESSAGE = 'The subject was changed by a concurrent request; nothing was saved. Reload it and retry.';

    /** The marking in the database is not the one this request loaded. */
    public const REASON_MARKING_CHANGED = 'marking_changed';

    /** The subject row no longer exists (or is no longer visible). */
    public const REASON_SUBJECT_MISSING = 'subject_missing';

    /** The versioned UPDATE of the subject found a newer version (Doctrine OptimisticLockException). */
    public const REASON_VERSION_CHANGED = 'version_changed';

    /** Deadlock, lock wait timeout or serialization failure while locking or writing the subject. */
    public const REASON_LOCK = 'lock';

    /**
     * @param string $workflowSlug Workflow of the apply
     * @param string $reason One of the REASON_* constants
     * @param list<string> $expectedPlaces Places this request loaded (empty when unknown)
     * @param list<string> $currentPlaces Places found in the database (empty when unknown)
     * @param int|null $retryAfterSeconds Retry-After hint; null = none
     */
    public function __construct(
        public readonly string $workflowSlug,
        public readonly string $reason,
        public readonly array $expectedPlaces = [],
        public readonly array $currentPlaces = [],
        ?int $retryAfterSeconds = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($retryAfterSeconds, self::MESSAGE, $previous);
    }

    /**
     * @param list<string> $expected
     * @param list<string> $current
     */
    public static function markingChanged(string $workflowSlug, array $expected, array $current, ?int $retryAfterSeconds): self
    {
        return new self($workflowSlug, self::REASON_MARKING_CHANGED, $expected, $current, $retryAfterSeconds);
    }

    /**
     * @param list<string> $expected
     */
    public static function subjectMissing(string $workflowSlug, array $expected, ?int $retryAfterSeconds): self
    {
        return new self($workflowSlug, self::REASON_SUBJECT_MISSING, $expected, [], $retryAfterSeconds);
    }

    public static function versionChanged(string $workflowSlug, Throwable $previous, ?int $retryAfterSeconds): self
    {
        return new self($workflowSlug, self::REASON_VERSION_CHANGED, [], [], $retryAfterSeconds, $previous);
    }

    public static function lockConflict(string $workflowSlug, Throwable $previous, ?int $retryAfterSeconds): self
    {
        return new self($workflowSlug, self::REASON_LOCK, [], [], $retryAfterSeconds, $previous);
    }
}
