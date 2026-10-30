<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow transition row.
 *
 * @extends AbstractType<WorkflowTransition>
 */
#[FormKitConfig('workflow')]
final class WorkflowTransitionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the transition name, label, source places and target places fields.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', ['label' => 'form.field.name']);
        $this->addText($builder, 'label', ['label' => 'form.field.label', 'required' => false]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            /** @var WorkflowTransition|null $transition */
            $transition = $event->getData();
            $form       = $event->getForm();

            $this->configurePlaceField($form, 'fromPlaces', 'form.field.from_places', $options['place_choices'], $transition?->getFromPlaces() ?? []);
            $this->configurePlaceField($form, 'toPlaces', 'form.field.to_places', $options['place_choices'], $transition?->getToPlaces() ?? []);
        });
    }

    /**
     * Configures the data class, the place_choices option and the translation domain.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowTransition::class,
            'empty_data'         => static fn (): WorkflowTransition => new WorkflowTransition('', [], []),
            'translation_domain' => 'NowoWorkflowBundle',
            'place_choices'      => [],
        ]);
        $resolver->setAllowedTypes('place_choices', 'array');
    }

    /**
     * @param FormInterface<WorkflowTransition|null> $form
     * @param list<string> $placeChoices
     * @param list<string> $selected
     */
    private function configurePlaceField(
        FormInterface $form,
        string $field,
        string $label,
        array $placeChoices,
        array $selected,
    ): void {
        if ($form->has($field)) {
            $form->remove($field);
        }

        // FormKit profile `workflow` (label / translation domain / row); the widget keeps its own classes (TomSelect
        // marker) and renders no convention help or placeholder.
        $form->add($field, PlaceMultiSelectType::class, $this->resolveFieldOptions($field, PlaceMultiSelectType::class, [
            'label'              => $label,
            'help'               => false,
            'placeholder'        => false,
            'translation_domain' => 'NowoWorkflowBundle',
            'attr'               => ['class' => PlaceMultiSelectType::widgetClass()],
            'choices'            => PlaceChoiceHelper::buildChoices($placeChoices, $selected),
        ]));
    }
}
