<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Form\PlaceChoiceHelper;
use PHPUnit\Framework\TestCase;

final class PlaceChoiceHelperTest extends TestCase
{
    public function testBuildChoicesMergesAndSortsPlaceNames(): void
    {
        $choices = PlaceChoiceHelper::buildChoices(['review', 'draft'], ['approved']);

        self::assertSame([
            'approved' => 'approved',
            'draft'    => 'draft',
            'review'   => 'review',
        ], $choices);
    }

    public function testBuildChoicesReturnsEmptyArrayWhenNoPlaces(): void
    {
        self::assertSame([], PlaceChoiceHelper::buildChoices([], []));
    }

    public function testExtractNamesFromSubmittedPlaces(): void
    {
        $names = PlaceChoiceHelper::extractNamesFromSubmittedPlaces([ // @phpstan-ignore argument.type
            ['name' => ' draft '],
            ['name'  => 'review'],
            ['label' => 'ignored'],
            ['name'  => ''],
            'invalid',
        ]);

        self::assertSame(['draft', 'review'], $names);
    }

    public function testExtractNamesIgnoresNonStringNameValues(): void
    {
        $names = PlaceChoiceHelper::extractNamesFromSubmittedPlaces([
            ['name' => 123],
        ]);

        self::assertSame([], $names);
    }
}
