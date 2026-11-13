<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Expression\GuardExpression;
use Nowo\WorkflowBundle\Expression\GuardExpressionException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function implode;
use function is_string;
use function trim;

/**
 * Form type for a workflow transition row.
 *
 * @extends AbstractType<WorkflowTransition>
 */
#[FormKitConfig('workflow')]
final class WorkflowTransitionType extends AbstractType
{
    use FormOptionsTrait;
    /** Since 1.15 (audit NO M2): place and transition names with tenancy. */
    public const STRICT_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    /**
     * Adds the transition name, label, source places and target places fields.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Since 1.16 (tenancy.lock_clone_structure): a locked clone's transition keeps its name, label and places.
        $locked = $options['structure_locked'];
        $this->addText($builder, 'name', ['label' => 'form.field.name', 'disabled' => $locked] + ($options['strict_names'] ? ['constraints' => [new Regex(self::STRICT_NAME_PATTERN, 'Use 1 to 64 lower-case letters, digits or underscores.')]] : []));
        $this->addText($builder, 'label', ['label' => 'form.field.label', 'required' => false, 'disabled' => $locked]);
        // Since 1.16: translatable label (key + domain), the plain label is the fallback.
        $this->addText($builder, 'labelKey', ['label' => 'form.field.label_key', 'required' => false, 'help' => 'form.help.label_key', 'disabled' => $locked, 'constraints' => LabelTranslationConstraints::key()]);
        $this->addText($builder, 'labelDomain', ['label' => 'form.field.label_domain', 'required' => false, 'disabled' => $locked, 'constraints' => LabelTranslationConstraints::domain($options['label_domains'])]);

        // Since 1.15: guards an editor configures per transition, shown only when the host configured their port.
        /** @var array{permission: bool, variables: list<string>|null, automatic: bool} $guards */
        $guards = $options['guards'];
        if ($guards['permission']) {
            $this->addText($builder, 'guardPermission', ['label' => 'form.field.guard_permission', 'required' => false, 'help' => 'form.help.guard_permission']);
        }
        if ($guards['variables'] !== null) {
            $variables = $guards['variables'];
            $this->addTextarea($builder, 'guardExpression', [
                'label'                       => 'form.field.guard_expression',
                'required'                    => false,
                'help'                        => 'form.help.guard_expression',
                'help_translation_parameters' => ['%variables%' => $variables === [] ? '—' : implode(', ', $variables)],
                'constraints'                 => [new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($variables): void {
                    if (!is_string($value) || trim($value) === '') {
                        return;
                    }
                    try {
                        GuardExpression::validate($value, $variables);
                    } catch (GuardExpressionException $exception) {
                        $context->addViolation($exception->getMessage());
                    }
                })],
            ]);
        }
        if ($guards['automatic']) {
            $this->addCheckbox($builder, 'automatic', ['label' => 'form.field.automatic', 'required' => false, 'help' => 'form.help.automatic']);
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            /** @var WorkflowTransition|null $transition */
            $transition = $event->getData();
            $form       = $event->getForm();

            $this->configurePlaceField($form, 'fromPlaces', 'form.field.from_places', $options['place_choices'], $transition?->getFromPlaces() ?? [], $options['structure_locked']);
            $this->configurePlaceField($form, 'toPlaces', 'form.field.to_places', $options['place_choices'], $transition?->getToPlaces() ?? [], $options['structure_locked']);
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
            'guards'             => ['permission' => false, 'variables' => null, 'automatic' => false],
            'strict_names'       => false,
            // Since 1.16: structure of a locked clone (only guards stay editable).
            'structure_locked' => false,
        ]);
        $resolver->setAllowedTypes('structure_locked', 'bool');
        $resolver->setAllowedTypes('strict_names', 'bool');
        // Since 1.16 (tenancy.label_domains): the translation domains a label key may use.
        $resolver->setDefault('label_domains', LabelTranslationConstraints::DEFAULT_DOMAINS);
        $resolver->setAllowedTypes('label_domains', 'string[]');
        $resolver->setAllowedTypes('place_choices', 'array');
        $resolver->setAllowedTypes('guards', 'array');
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
        bool $disabled = false,
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
        ] + ($disabled ? ['disabled' => true] : [])));
    }
}
