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
 */
#[FormKitConfig('workflow')]
final class WorkflowTransitionType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', ['label' => 'form.field.name']);
        $this->addText($builder, 'label', ['label' => 'form.field.label', 'required' => false]);

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
            'data_class'         => WorkflowTransition::class,
            'empty_data'         => static fn (): WorkflowTransition => new WorkflowTransition('', [], []),
            'translation_domain' => 'NowoWorkflowBundle',
            'place_choices'      => [],
        ]);
        $resolver->setAllowedTypes('place_choices', 'array');
    }

    /**
     * @param list<string> $placeChoices
     * @param list<string> $selected
     */
    private static function configurePlaceField(
        FormInterface $form,
        string $field,
        string $label,
        array $placeChoices,
        array $selected,
    ): void {
        if ($form->has($field)) {
            $form->remove($field);
        }

        $form->add($field, PlaceMultiSelectType::class, [
            'label'   => $label,
            'choices' => PlaceChoiceHelper::buildChoices($placeChoices, $selected),
        ]);
    }
}
