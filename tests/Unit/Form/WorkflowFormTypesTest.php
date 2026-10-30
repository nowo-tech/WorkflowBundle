<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Form\PlaceMultiSelectType;
use Nowo\WorkflowBundle\Form\WorkflowMatchRuleType;
use Nowo\WorkflowBundle\Form\WorkflowPlaceType;
use Nowo\WorkflowBundle\Form\WorkflowTransitionType;
use Nowo\WorkflowBundle\Tests\Support\FormKitTestSupport;
use ReflectionMethod;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Test\TypeTestCase;

final class WorkflowFormTypesTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new WorkflowPlaceType()),
            FormKitTestSupport::withMerger(new WorkflowMatchRuleType()),
            FormKitTestSupport::withMerger(new WorkflowTransitionType()),
            FormKitTestSupport::withMerger(new PlaceMultiSelectType()),
        ];
    }

    public function testWorkflowPlaceTypeSubmit(): void
    {
        $place = new WorkflowPlace('initial');
        $form  = $this->factory->create(WorkflowPlaceType::class, $place);
        $form->submit([
            'name'      => 'draft',
            'label'     => 'Draft',
            'sortOrder' => 1,
        ]);

        self::assertTrue($form->isSynchronized());
        /** @var WorkflowPlace $place */
        $place = $form->getData();
        self::assertSame('draft', $place->getName());
    }

    public function testWorkflowMatchRuleTypeSubmit(): void
    {
        $rule = new WorkflowMatchRule('tenant', 'acme');
        $form = $this->factory->create(WorkflowMatchRuleType::class, $rule);
        $form->submit([
            'parameterKey'   => 'tenant',
            'parameterValue' => 'acme',
            'sortOrder'      => 0,
        ]);

        self::assertTrue($form->isSynchronized());
        /** @var WorkflowMatchRule $rule */
        $rule = $form->getData();
        self::assertSame('tenant', $rule->getParameterKey());
    }

    public function testWorkflowTransitionTypeSubmit(): void
    {
        $transition = new WorkflowTransition('approve', ['draft'], ['approved']);
        $form       = $this->factory->create(WorkflowTransitionType::class, $transition, [
            'place_choices' => ['draft' => 'draft', 'approved' => 'approved'],
        ]);
        $form->submit([
            'name'       => 'approve',
            'label'      => 'Approve',
            'fromPlaces' => ['draft'],
            'toPlaces'   => ['approved'],
        ]);

        self::assertTrue($form->isSynchronized());
        /** @var WorkflowTransition $transition */
        $transition = $form->getData();
        self::assertSame('approve', $transition->getName());
    }

    public function testWorkflowTransitionTypeCreatesEmptyData(): void
    {
        $form = $this->factory->create(WorkflowTransitionType::class, null, [
            'place_choices' => ['draft' => 'draft'],
        ]);
        $form->submit([
            'name'       => 'approve',
            'label'      => 'Approve',
            'fromPlaces' => ['draft'],
            'toPlaces'   => ['approved'],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertInstanceOf(WorkflowTransition::class, $form->getData());
    }

    public function testWorkflowTransitionTypeReconfiguresPlaceFieldsWhenDataChanges(): void
    {
        $transition = new WorkflowTransition('approve', ['draft'], ['approved']);
        $form       = $this->factory->create(WorkflowTransitionType::class, $transition, [
            'place_choices' => ['draft' => 'draft', 'approved' => 'approved'],
        ]);

        $form->setData(new WorkflowTransition('reject', ['approved'], ['draft']));

        self::assertTrue($form->has('fromPlaces'));
        self::assertTrue($form->has('toPlaces'));
    }

    public function testWorkflowTransitionTypeRemovesExistingPlaceFieldBeforeReAdding(): void
    {
        $form = $this->factory->createNamedBuilder('transition')->getForm();
        $form->add('fromPlaces', TextType::class);

        $method = new ReflectionMethod(WorkflowTransitionType::class, 'configurePlaceField');
        $method->setAccessible(true);
        $method->invoke(FormKitTestSupport::withMerger(new WorkflowTransitionType()), $form, 'fromPlaces', 'form.field.from_places', ['draft' => 'draft'], ['draft']);

        self::assertTrue($form->has('fromPlaces'));
    }

    public function testPlaceFieldsResolveThroughTheFormKitProfile(): void
    {
        $form = $this->factory->create(WorkflowTransitionType::class, new WorkflowTransition('approve', ['draft'], ['approved']), [
            'place_choices' => ['draft' => 'draft', 'approved' => 'approved'],
        ]);

        foreach (['fromPlaces' => 'form.field.from_places', 'toPlaces' => 'form.field.to_places'] as $field => $label) {
            $config = $form->get($field)->getConfig();
            self::assertInstanceOf(PlaceMultiSelectType::class, $config->getType()->getInnerType());
            self::assertSame($label, $config->getOption('label'));
            self::assertSame('NowoWorkflowBundle', $config->getOption('translation_domain'));
            self::assertNull($config->getOption('help'));
            self::assertSame(PlaceMultiSelectType::widgetClass(), $config->getOption('attr')['class']);
            self::assertArrayNotHasKey('placeholder', $config->getOption('attr'));
        }
    }
}
