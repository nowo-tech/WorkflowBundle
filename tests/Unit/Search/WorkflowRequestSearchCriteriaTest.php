<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Search;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function range;

final class WorkflowRequestSearchCriteriaTest extends TestCase
{
    #[Test]
    public function conditionsAreCollectedInOrder(): void
    {
        $criteria = (new WorkflowRequestSearchCriteria())
            ->whereAny(['requesterId' => 5, 'approverId' => [5, 6]])
            ->whereIn('departmentId', ['a' => 1, 'b' => 2]);

        self::assertSame(WorkflowRequestSearchCriteria::DEFAULT_MAX_VALUES, $criteria->maxValues);
        self::assertFalse($criteria->isNone());
        self::assertSame([], $criteria->overflowingFields());
        self::assertSame([['requesterId' => [5], 'approverId' => [5, 6]], ['departmentId' => [1, 2]]], $criteria->conditions());
        self::assertTrue($criteria->none()->isNone());
    }

    #[Test]
    public function anEmptyConditionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WorkflowRequestSearchCriteria())->whereAny([]);
    }

    #[Test]
    public function anInvalidFieldIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid search condition on "r.id"');

        (new WorkflowRequestSearchCriteria())->whereIn('r.id', [1]);
    }

    #[Test]
    public function tooManyValuesAreRecordedNeverCut(): void
    {
        $criteria = (new WorkflowRequestSearchCriteria(3))->whereIn('id', [1, 2, 3])->whereAny(['userId' => range(1, 4), 'id' => 1]);

        self::assertSame(['userId'], $criteria->overflowingFields());
        self::assertSame(range(1, 4), $criteria->conditions()[1]['userId'], 'kept whole: the search fails closed instead');
    }
}
