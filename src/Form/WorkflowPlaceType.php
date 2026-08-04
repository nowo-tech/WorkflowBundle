<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow place row.
 */
#[FormKitConfig('workflow')]
final class WorkflowPlaceType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', ['label' => 'form.field.name']);
        $this->addText($builder, 'label', ['label' => 'form.field.label', 'required' => false]);
        $this->addInteger($builder, 'sortOrder', ['label' => 'form.field.order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowPlace::class,
            'empty_data'         => static fn (): WorkflowPlace => new WorkflowPlace(''),
            'translation_domain' => 'NowoWorkflowBundle',
        ]);
    }
}
