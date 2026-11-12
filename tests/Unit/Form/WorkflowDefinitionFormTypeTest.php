<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Form\WorkflowMatchRuleType;
use Nowo\WorkflowBundle\Form\WorkflowPlaceType;
use Nowo\WorkflowBundle\Form\WorkflowTransitionType;
use Nowo\WorkflowBundle\Tests\Support\FormKitTestSupport;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class WorkflowDefinitionFormTypeTest extends TypeTestCase
{
    /**
     * @return list<object>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new WorkflowDefinitionFormType()),
            FormKitTestSupport::withMerger(new WorkflowPlaceType()),
            FormKitTestSupport::withMerger(new WorkflowMatchRuleType()),
            FormKitTestSupport::withMerger(new WorkflowTransitionType()),
        ];
    }

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

    /**
     * 1.15.2: a state machine transition has exactly one target place; a workflow transition may have several.
     */
    public function testStateMachineTransitionWithSeveralTargetsIsInvalid(): void
    {
        foreach ([WorkflowType::StateMachine->value => false, WorkflowType::Workflow->value => true] as $type => $valid) {
            $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order', WorkflowType::from($type));
            foreach (['draft', 'done', 'cancelled'] as $place) {
                $definition->addPlace(new WorkflowPlace($place));
            }
            $definition->addTransition(new WorkflowTransition('close', ['draft'], ['done']));

            $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, [
                'section' => WorkflowDefinitionFormSection::Transitions,
            ]);
            $form->submit(['transitions' => [['name' => 'close', 'label' => '', 'fromPlaces' => ['draft'], 'toPlaces' => ['done', 'cancelled']]]]);

            self::assertSame($valid, $form->isValid(), $type);
            if (!$valid) {
                self::assertStringContainsString('exactly one target place', (string) $form->getErrors(true));
            }
        }
    }

    /**
     * 1.15.2: the shape is checked on the submitted type: switching a workflow to state_machine in General cannot leave
     * a transition with several targets behind; a transition without source or target is refused for every type.
     */
    public function testSwitchingToStateMachineChecksTheExistingTransitions(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order', WorkflowType::Workflow);
        foreach (['draft', 'done', 'cancelled'] as $place) {
            $definition->addPlace(new WorkflowPlace($place));
        }
        $definition->addTransition(new WorkflowTransition('close', ['draft'], ['done', 'cancelled']));
        $general = [
            'name'            => 'Order', 'slug' => 'order', 'initialPlace' => 'draft', 'subjectClass' => 'App\\Entity\\Order',
            'markingProperty' => 'status', 'enabled' => true, 'priority' => 0, 'description' => '',
        ];

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, ['section' => WorkflowDefinitionFormSection::General]);
        $form->submit($general + ['type' => WorkflowType::StateMachine->value]);
        self::assertFalse($form->isValid());
        self::assertStringContainsString('Transition "close": a state machine transition has exactly one target place.', (string) $form->get('type')->getErrors());

        $definition->setType(WorkflowType::Workflow);
        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, ['section' => WorkflowDefinitionFormSection::General]);
        $form->submit($general + ['type' => WorkflowType::Workflow->value]);
        self::assertTrue($form->isValid(), 'a workflow may have several targets');

        $form = $this->factory->create(WorkflowDefinitionFormType::class, $definition, ['section' => WorkflowDefinitionFormSection::Transitions]);
        $form->submit(['transitions' => [['name' => 'close', 'label' => '', 'fromPlaces' => [], 'toPlaces' => ['done']]]]);
        self::assertFalse($form->isValid());
        self::assertStringContainsString('at least one source place and one target place', (string) $form->getErrors());
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
