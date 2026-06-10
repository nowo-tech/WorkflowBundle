<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow transition row.
 */
final class WorkflowTransitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.field.name'])
            ->add('label', TextType::class, ['label' => 'form.field.label', 'required' => false]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($options): void {
            /** @var WorkflowTransition|null $transition */
            $transition = $event->getData();
            $form       = $event->getForm();

            self::configurePlaceField($form, 'fromPlaces', 'form.field.from_places', $options['place_choices'], $transition?->getFromPlaces() ?? []);
            self::configurePlaceField($form, 'toPlaces', 'form.field.to_places', $options['place_choices'], $transition?->getToPlaces() ?? []);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkflowTransition::class,
            'translation_domain' => 'nowo_workflow',
            'place_choices' => [],
        ]);
        $resolver->setAllowedTypes('place_choices', 'array');
    }

    /**
     * @param list<string> $placeChoices
     * @param list<string> $selected
     */
    private static function configurePlaceField(
        \Symfony\Component\Form\FormInterface $form,
        string $field,
        string $label,
        array $placeChoices,
        array $selected,
    ): void {
        if ($form->has($field)) {
            $form->remove($field);
        }

        $form->add($field, PlaceMultiSelectType::class, [
            'label' => $label,
            'choices' => PlaceChoiceHelper::buildChoices($placeChoices, $selected),
        ]);
    }
}
