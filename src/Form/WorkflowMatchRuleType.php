<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow match rule row.
 */
final class WorkflowMatchRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('parameterKey', TextType::class, ['label' => 'form.field.parameter_key'])
            ->add('parameterValue', TextType::class, ['label' => 'form.field.parameter_value'])
            ->add('sortOrder', IntegerType::class, ['label' => 'form.field.order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowMatchRule::class,
            'empty_data'         => static fn (): WorkflowMatchRule => new WorkflowMatchRule('', ''),
            'translation_domain' => 'NowoWorkflowBundle',
        ]);
    }
}
