<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UiKitBundle\Theme\ThemeSlots;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Searchable multiselect for workflow place names (FormKit Type, profile {@code workflow}; embedded by
 * {@see WorkflowTransitionType}, which resolves its options through the profile).
 *
 * @extends AbstractType<list<string>>
 */
#[FormKitConfig('workflow')]
final class PlaceMultiSelectType extends AbstractType
{
    use FormOptionsTrait;

    /** Marker class the transitions page script enhances (TomSelect). */
    public const CSS_CLASS = 'nowo-place-multiselect';

    /**
     * Widget classes: the UiKit `input_full` slot plus the {@see self::CSS_CLASS} marker.
     */
    public static function widgetClass(): string
    {
        return ThemeSlots::semantic('input_full') . ' ' . self::CSS_CLASS;
    }

    /**
     * Configures a multiple choice of place names (TomSelect-enhanced on the transitions page).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => true,
            'required' => false,
            'attr'     => [
                'class' => self::widgetClass(),
            ],
        ]);
        $resolver->setRequired(['choices']);
        $resolver->setAllowedTypes('choices', 'array');
    }

    /**
     * Returns the parent form type (ChoiceType).
     */
    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * Returns the block prefix used by form themes.
     */
    public function getBlockPrefix(): string
    {
        return 'place_multi_select';
    }
}
