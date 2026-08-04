<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for a workflow match rule row.
 */
#[FormKitConfig('workflow')]
final class WorkflowMatchRuleType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'parameterKey', ['label' => 'form.field.parameter_key']);
        $this->addText($builder, 'parameterValue', ['label' => 'form.field.parameter_value']);
        $this->addInteger($builder, 'sortOrder', ['label' => 'form.field.order']);
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
