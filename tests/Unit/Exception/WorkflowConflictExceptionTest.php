<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Exception;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowConflictExceptionTest extends TestCase
{
    #[Test]
    public function everyConflictIsARetryableTransientFailureWithAConstantMessage(): void
    {
        $storage   = new RuntimeException('SQLSTATE[40001]: SELECT secret');
        $conflicts = [
            WorkflowConflictException::markingChanged('leave', ['draft'], ['review'], 2),
            WorkflowConflictException::subjectMissing('leave', ['draft'], null),
            WorkflowConflictException::versionChanged('leave', $storage, 1),
            WorkflowConflictException::lockConflict('leave', $storage, 1),
        ];

        self::assertSame(
            [WorkflowConflictException::REASON_MARKING_CHANGED, WorkflowConflictException::REASON_SUBJECT_MISSING, WorkflowConflictException::REASON_VERSION_CHANGED, WorkflowConflictException::REASON_LOCK],
            array_map(static fn (WorkflowConflictException $conflict): string => $conflict->reason, $conflicts),
        );
        foreach ($conflicts as $conflict) {
            self::assertSame(TransientStorageFailure::class, get_parent_class($conflict));
            self::assertContains(LockConflictExceptionInterface::class, class_implements($conflict));
            self::assertSame(WorkflowConflictException::MESSAGE, $conflict->getMessage());
            self::assertSame('leave', $conflict->workflowSlug);
        }
        self::assertSame(['draft'], $conflicts[0]->expectedPlaces);
        self::assertSame(['review'], $conflicts[0]->currentPlaces);
        self::assertSame(2, $conflicts[0]->retryAfterSeconds());
        self::assertNull($conflicts[1]->retryAfterSeconds());
        self::assertSame($storage, $conflicts[3]->getPrevious());
    }
}
