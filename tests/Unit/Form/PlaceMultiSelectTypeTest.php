<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Form\PlaceMultiSelectType;
use Symfony\Component\Form\Test\TypeTestCase;

final class PlaceMultiSelectTypeTest extends TypeTestCase
{
    public function testSubmitMultiplePlaceNames(): void
    {
        $form = $this->factory->create(PlaceMultiSelectType::class, null, [
            'choices' => ['draft' => 'draft', 'approved' => 'approved'],
        ]);

        $form->submit(['draft', 'approved']);

        self::assertTrue($form->isSynchronized());
        self::assertSame(['draft', 'approved'], $form->getData());
    }

    public function testBlockPrefix(): void
    {
        $type = new PlaceMultiSelectType();

        self::assertSame('place_multi_select', $type->getBlockPrefix());
    }
}
