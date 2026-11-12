<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_array;

/**
 * CRUD form for persisted workflow definitions (full or single-section).
 *
 * @extends AbstractType<WorkflowDefinition>
 */
#[FormKitConfig('workflow')]
final class WorkflowDefinitionFormType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields of the selected definition section.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $options['section'];

        if ($section === WorkflowDefinitionFormSection::General) {
            $this->addGeneralFields($builder, $options['slug_locked'], $options['binding_locked']);
        }

        if ($section === WorkflowDefinitionFormSection::MatchRules) {
            $this->addMatchRulesFields($builder);
        }

        if ($section === WorkflowDefinitionFormSection::Places) {
            $this->addPlacesFields($builder, $options['strict_names']);
        }

        if ($section === WorkflowDefinitionFormSection::Transitions) {
            $this->addTransitionsFields($builder, $options['guards'], $options['strict_names']);
        }
    }

    /**
     * @param FormBuilderInterface<WorkflowDefinition|null> $builder
     */
    private function addGeneralFields(FormBuilderInterface $builder, bool $slugLocked, bool $bindingLocked = false): void
    {
        $this->addText($builder, 'name', ['label' => 'form.field.name']);
        // With tenancy the slug ties the versions of a definition together: set once, when it is created.
        $this->addText($builder, 'slug', ['label' => 'form.field.slug', 'disabled' => $slugLocked]);
        $this->addWithDefaults($builder, 'type', EnumType::class, [
            'class'        => WorkflowType::class,
            'label'        => 'form.field.type',
            'choice_label' => static fn (WorkflowType $type): string => 'workflow_type.' . $type->value,
            'disabled'     => $bindingLocked,
        ]);
        $this->addText($builder, 'initialPlace', ['label' => 'form.field.initial_place']);
        // Audit NO M2: in a tenant scope the runtime binding comes from the template or tenancy.subject_classes.
        $this->addText($builder, 'subjectClass', ['label' => 'form.field.subject_class', 'disabled' => $bindingLocked]);
        $this->addText($builder, 'markingProperty', ['label' => 'form.field.marking_property', 'disabled' => $bindingLocked]);
        $this->addCheckbox($builder, 'enabled', ['label' => 'form.field.enabled', 'required' => false]);
        $this->addInteger($builder, 'priority', ['label' => 'form.field.priority']);
        $this->addTextarea($builder, 'description', ['label' => 'form.field.description', 'required' => false]);
    }

    /**
     * @param FormBuilderInterface<WorkflowDefinition|null> $builder
     */
    private function addMatchRulesFields(FormBuilderInterface $builder): void
    {
        $this->addWithDefaults($builder, 'matchRules', CollectionType::class, [
            'entry_type'     => WorkflowMatchRuleType::class,
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    /**
     * @param FormBuilderInterface<WorkflowDefinition|null> $builder
     */
    private function addPlacesFields(FormBuilderInterface $builder, bool $strictNames = false): void
    {
        $this->addWithDefaults($builder, 'places', CollectionType::class, [
            'entry_type'     => WorkflowPlaceType::class,
            'entry_options'  => ['strict_names' => $strictNames],
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    /**
     * @param FormBuilderInterface<WorkflowDefinition|null> $builder
     * @param array{permission: bool, variables: list<string>|null, automatic: bool} $guards
     */
    private function addTransitionsFields(FormBuilderInterface $builder, array $guards, bool $strictNames = false): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($guards, $strictNames): void {
            $definition = $event->getData();
            $placeNames = $definition instanceof WorkflowDefinition ? $definition->getPlaceNames() : [];

            self::configureTransitionsField($event->getForm(), $placeNames, $guards, $strictNames);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($guards, $strictNames): void {
            $definition = $event->getForm()->getData();
            $submitted  = $event->getData();

            $placeNames = is_array($submitted) && isset($submitted['places'])
                ? PlaceChoiceHelper::extractNamesFromSubmittedPlaces($submitted['places'])
                : ($definition instanceof WorkflowDefinition ? $definition->getPlaceNames() : []);

            self::configureTransitionsField($event->getForm(), $placeNames, $guards, $strictNames);
        });
    }

    /**
     * @param FormInterface<WorkflowDefinition|null> $form
     * @param list<string> $placeNames
     * @param array{permission: bool, variables: list<string>|null, automatic: bool} $guards
     */
    private static function configureTransitionsField(FormInterface $form, array $placeNames, array $guards, bool $strictNames = false): void
    {
        if ($form->has('transitions')) {
            $form->remove('transitions');
        }

        $form->add('transitions', CollectionType::class, [
            'entry_type'     => WorkflowTransitionType::class,
            'entry_options'  => ['place_choices' => $placeNames, 'guards' => $guards, 'strict_names' => $strictNames],
            'allow_add'      => true,
            'allow_delete'   => true,
            'by_reference'   => false,
            'label'          => false,
            'prototype'      => true,
            'prototype_name' => '__name__',
        ]);
    }

    /**
     * Configures the data class, the section option and the translation domain.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowDefinition::class,
            'translation_domain' => 'NowoWorkflowBundle',
            'section'            => WorkflowDefinitionFormSection::General,
            // Since 1.15: guard fields of the transition editor (WorkflowUiTenancy::guardFields()).
            'guards'      => ['permission' => false, 'variables' => null, 'automatic' => false],
            'slug_locked' => false,
            // Since 1.15 (audit NO M2): tenant scopes cannot change the runtime binding; strict place / transition names.
            'binding_locked' => false,
            'strict_names'   => false,
        ]);
        $resolver->setAllowedTypes('section', WorkflowDefinitionFormSection::class);
        $resolver->setAllowedTypes('guards', 'array');
        $resolver->setAllowedTypes('slug_locked', 'bool');
        $resolver->setAllowedTypes('binding_locked', 'bool');
        $resolver->setAllowedTypes('strict_names', 'bool');
    }
}
