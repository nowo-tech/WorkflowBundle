<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Form type for a workflow place row.
 *
 * @extends AbstractType<WorkflowPlace>
 */
#[FormKitConfig('workflow')]
final class WorkflowPlaceType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the place name, label and sort order fields.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', ['label' => 'form.field.name'] + ($options['strict_names'] ? ['constraints' => [new Regex(WorkflowTransitionType::STRICT_NAME_PATTERN, 'Use 1 to 64 lower-case letters, digits or underscores.')]] : []));
        $this->addText($builder, 'label', ['label' => 'form.field.label', 'required' => false]);
        // Since 1.16: translatable label (key + domain), the plain label is the fallback.
        $this->addText($builder, 'labelKey', ['label' => 'form.field.label_key', 'required' => false, 'help' => 'form.help.label_key']);
        $this->addText($builder, 'labelDomain', ['label' => 'form.field.label_domain', 'required' => false]);
        $this->addInteger($builder, 'sortOrder', ['label' => 'form.field.order']);
    }

    /**
     * Configures the data class and the translation domain.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => WorkflowPlace::class,
            'empty_data'         => static fn (): WorkflowPlace => new WorkflowPlace(''),
            'translation_domain' => 'NowoWorkflowBundle',
            'strict_names'       => false,
        ]);
        $resolver->setAllowedTypes('strict_names', 'bool');
    }
}
