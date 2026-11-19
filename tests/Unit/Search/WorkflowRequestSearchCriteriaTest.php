<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Search;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function range;

final class WorkflowRequestSearchCriteriaTest extends TestCase
{
    #[Test]
    public function conditionsAreCollectedInOrder(): void
    {
        $criteria = (new WorkflowRequestSearchCriteria())
            ->whereAny(['requesterId' => 5, 'approverId' => [5, 6]])
            ->whereIn('departmentId', ['a' => 1, 'b' => 2]);

        self::assertFalse($criteria->isNothing());
        self::assertSame([['requesterId' => [5], 'approverId' => [5, 6]], ['departmentId' => [1, 2]]], $criteria->conditions());
        self::assertTrue($criteria->nothing()->isNothing());
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
    public function tooManyValuesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WorkflowRequestSearchCriteria())->whereIn('id', array_fill(0, WorkflowRequestSearchCriteria::MAX_VALUES + 1, 1));
    }

    #[Test]
    public function theMostValuesAreAccepted(): void
    {
        $criteria = (new WorkflowRequestSearchCriteria())->whereIn('id', range(1, WorkflowRequestSearchCriteria::MAX_VALUES));

        self::assertCount(WorkflowRequestSearchCriteria::MAX_VALUES, $criteria->conditions()[0]['id']);
    }
}
