<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class WorkflowDefinitionFormTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testGeneralSectionSubmit(): void
    {
        $definition = new WorkflowDefinition('Old', 'old_slug', 'draft', 'App\\Entity\\Subject');
        $form       = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::General,
        ]);

        $form->submit([
            'name'            => 'Purchase order',
            'slug'            => 'purchase_order',
            'type'            => WorkflowType::StateMachine->value,
            'initialPlace'    => 'draft',
            'subjectClass'    => 'App\\Entity\\PurchaseOrder',
            'markingProperty' => 'status',
            'enabled'         => true,
            'priority'        => 10,
            'description'     => 'Demo workflow',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Purchase order', $definition->getName());
        self::assertSame('purchase_order', $definition->getSlug());
    }

    public function testTransitionsSectionBuildsPlaceChoicesFromDefinition(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $definition->addPlace(new WorkflowPlace('draft'));
        $definition->addPlace(new WorkflowPlace('approved'));
        $definition->addTransition(new WorkflowTransition('approve', ['draft'], ['approved']));

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::Transitions,
        ]);

        self::assertTrue($form->has('transitions'));
        self::assertCount(1, $form->get('transitions'));
    }

    public function testTransitionsSectionUsesSubmittedPlacesOnPreSubmit(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::Transitions,
        ]);

        $form->submit([
            'places' => [
                ['name' => 'draft', 'label' => '', 'sortOrder' => 0],
                ['name' => 'shipped', 'label' => '', 'sortOrder' => 1],
            ],
            'transitions' => [
                [
                    'name'       => 'ship',
                    'label'      => '',
                    'fromPlaces' => ['draft'],
                    'toPlaces'   => ['shipped'],
                ],
            ],
        ], false);

        self::assertTrue($form->has('transitions'));
    }

    public function testPlacesSectionSubmit(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::Places,
        ]);

        $form->submit([
            'places' => [
                [
                    'name'      => 'draft',
                    'label'     => 'Draft',
                    'sortOrder' => 0,
                ],
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $definition->getPlaces());
    }

    public function testMatchRulesSectionSubmit(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::MatchRules,
        ]);

        $form->submit([
            'matchRules' => [
                [
                    'parameterKey'   => 'tenant',
                    'parameterValue' => 'acme',
                    'sortOrder'      => 0,
                ],
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $definition->getMatchRules());
    }
}
