<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Search;

use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

final class WorkflowRequestAccessCheckEventTest extends TestCase
{
    #[Test]
    public function denyByDefaultAndDenyWins(): void
    {
        $event = new WorkflowRequestAccessCheckEvent('leave', 'App\LeaveRequest', 'alice', 7, [
            new WorkflowRequestRef('1', 'pending'),
            new WorkflowRequestRef('2', 'approved'),
            new WorkflowRequestRef('3', 'pending'),
        ]);

        self::assertSame('leave', $event->subject);
        self::assertSame('App\LeaveRequest', $event->subjectClass);
        self::assertSame('alice', $event->userIdentifier);
        self::assertSame(7, $event->tenant);
        self::assertSame(['1', '2', '3'], array_map(static fn (WorkflowRequestRef $r): string => $r->id, $event->requests()));
        self::assertSame('approved', $event->requests()[1]->status);

        self::assertNull($event->decision('1'));
        self::assertFalse($event->isVisible('1'), 'no listener: hidden');

        $event->allow('1');
        $event->allow('2');
        $event->deny('2');
        $event->allow('2');
        $event->deny('3');
        $event->allow('3');
        $event->allow('99');
        $event->deny('98');

        self::assertTrue($event->isVisible('1'));
        self::assertTrue($event->decision('1'));
        self::assertFalse($event->isVisible('2'), 'a later allow never undoes a deny');
        self::assertFalse($event->decision('2'));
        self::assertFalse($event->isVisible('3'));
        self::assertFalse($event->isVisible('99'), 'not a candidate');
        self::assertNull($event->decision('99'));
        self::assertNull($event->decision('98'));
    }
}
