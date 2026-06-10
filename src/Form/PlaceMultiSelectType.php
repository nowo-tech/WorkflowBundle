<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Searchable multiselect for workflow place names.
 */
final class PlaceMultiSelectType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => true,
            'required' => false,
            'attr' => [
                'class' => 'form-select nowo-place-multiselect',
            ],
        ]);
        $resolver->setRequired(['choices']);
        $resolver->setAllowedTypes('choices', 'array');
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'place_multi_select';
    }
}
