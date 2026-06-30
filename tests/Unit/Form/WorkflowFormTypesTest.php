<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Form\WorkflowMatchRuleType;
use Nowo\WorkflowBundle\Form\WorkflowPlaceType;
use Nowo\WorkflowBundle\Form\WorkflowTransitionType;
use ReflectionMethod;
use Symfony\Component\Form\Test\TypeTestCase;

final class WorkflowFormTypesTest extends TypeTestCase
{
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
        $form->add('fromPlaces', \Symfony\Component\Form\Extension\Core\Type\TextType::class);

        $method = new ReflectionMethod(WorkflowTransitionType::class, 'configurePlaceField');
        $method->setAccessible(true);
        $method->invoke(null, $form, 'fromPlaces', 'form.field.from_places', ['draft' => 'draft'], ['draft']);

        self::assertTrue($form->has('fromPlaces'));
    }
}
