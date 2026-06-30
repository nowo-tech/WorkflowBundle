<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_array;

/**
 * CRUD form for persisted workflow definitions (full or single-section).
 */
final class WorkflowDefinitionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $options['section'];

        if ($section === WorkflowDefinitionFormSection::General) {
            $this->addGeneralFields($builder);
        }

        if ($section === WorkflowDefinitionFormSection::MatchRules) {
            $this->addMatchRulesFields($builder);
        }

        if ($section === WorkflowDefinitionFormSection::Places) {
            $this->addPlacesFields($builder);
        }

        if ($section === WorkflowDefinitionFormSection::Transitions) {
            $this->addTransitionsFields($builder);
        }
    }

    private function addGeneralFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.field.name'])
            ->add('slug', TextType::class, ['label' => 'form.field.slug'])
            ->add('type', EnumType::class, [
                'class'        => WorkflowType::class,
                'label'        => 'form.field.type',
                'choice_label' => static fn (WorkflowType $type): string => 'workflow_type.' . $type->value,
            ])
            ->add('initialPlace', TextType::class, ['label' => 'form.field.initial_place'])
            ->add('subjectClass', TextType::class, ['label' => 'form.field.subject_class'])
            ->add('markingProperty', TextType::class, ['label' => 'form.field.marking_property'])
            ->add('enabled', CheckboxType::class, ['label' => 'form.field.enabled', 'required' => false])
            ->add('priority', IntegerType::class, ['label' => 'form.field.priority'])
            ->add('description', TextareaType::class, ['label' => 'form.field.description', 'required' => false]);
    }

    private function addMatchRulesFields(FormBuilderInterface $builder): void
    {
        $builder->add('matchRules', CollectionType::class, [
            'entry_type'     => WorkflowMatchRuleType::class,
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    private function addPlacesFields(FormBuilderInterface $builder): void
    {
        $builder->add('places', CollectionType::class, [
            'entry_type'     => WorkflowPlaceType::class,
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    private function addTransitionsFields(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $definition = $event->getData();
            $placeNames = $definition instanceof WorkflowDefinition ? $definition->getPlaceNames() : [];

            self::configureTransitionsField($event->getForm(), $placeNames);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $definition = $event->getForm()->getData();
            $submitted  = $event->getData();

            $placeNames = is_array($submitted) && isset($submitted['places'])
                ? PlaceChoiceHelper::extractNamesFromSubmittedPlaces($submitted['places'])
                : ($definition instanceof WorkflowDefinition ? $definition->getPlaceNames() : []);

            self::configureTransitionsField($event->getForm(), $placeNames);
        });
    }

    /**
     * @param list<string> $placeNames
     */
    private static function configureTransitionsField(FormInterface $form, array $placeNames): void
    {
        if ($form->has('transitions')) {
            $form->remove('transitions');
        }

        $form->add('transitions', CollectionType::class, [
            'entry_type'     => WorkflowTransitionType::class,
            'entry_options'  => ['place_choices' => $placeNames],
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowDefinition::class,
            'translation_domain' => 'NowoWorkflowBundle',
            'section'            => WorkflowDefinitionFormSection::General,
        ]);
        $resolver->setAllowedTypes('section', WorkflowDefinitionFormSection::class);
    }
}
