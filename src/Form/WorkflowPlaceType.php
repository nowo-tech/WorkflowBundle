<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow place row.
 */
final class WorkflowPlaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.field.name'])
            ->add('label', TextType::class, ['label' => 'form.field.label', 'required' => false])
            ->add('sortOrder', IntegerType::class, ['label' => 'form.field.order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkflowPlace::class,
            'translation_domain' => 'nowo_workflow',
        ]);
    }
}
