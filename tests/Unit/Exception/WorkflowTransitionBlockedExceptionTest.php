<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Exception;

use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\TransitionBlockerList;

final class WorkflowTransitionBlockedExceptionTest extends TestCase
{
    public function testMessageListsEveryBlockerMessage(): void
    {
        $exception = new WorkflowTransitionBlockedException('expense_approval', 'approve', new TransitionBlockerList([
            new TransitionBlocker('Budget exceeded.', 'budget'),
            new TransitionBlocker('Approver is the requester.', 'four_eyes'),
        ]));

        self::assertSame('Transition "approve" is not enabled on workflow "expense_approval": Budget exceeded. Approver is the requester.', $exception->getMessage());
        self::assertSame(['Budget exceeded.', 'Approver is the requester.'], $exception->getBlockerMessages());
        self::assertSame(['budget', 'four_eyes'], $exception->getBlockerCodes());
        self::assertNull($exception->getPrevious());
    }

    public function testMessageWithoutBlockers(): void
    {
        $exception = new WorkflowTransitionBlockedException('expense_approval', 'approve', new TransitionBlockerList());

        self::assertSame('Transition "approve" is not enabled on workflow "expense_approval".', $exception->getMessage());
        self::assertSame([], $exception->getBlockerMessages());
        self::assertSame([], $exception->getBlockerCodes());
    }
}
