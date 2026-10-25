<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;

/**
 * "Create" form of each workflow playground page (REQ-TWIG-005). The subject option picks the fields; posted names
 * stay flat (title, document_type, tenant, department, region, amount_tier) as the demo controller reads them.
 * CSRF uses the demo's stateless "submit" token id (config/packages/csrf.yaml).
 *
 * @extends AbstractType<array<string, string|null>>
 */
final class PlaygroundSubjectType extends AbstractType
{
    public const SUBJECTS = ['order', 'change_request', 'document', 'expense', 'purchase_order'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $subject = $options['subject'];
        $col     = static fn (string $class): array => ['class' => $class];

        $builder->add('title', TextType::class, [
            'label'    => 'playground.orders.title_field',
            'required' => in_array($subject, ['order', 'change_request', 'document'], true),
            'row_attr' => $col(match ($subject) {
                'document'       => 'col-md-5',
                'expense'        => 'col-md-4',
                'purchase_order' => 'col-md-3',
                default          => 'col-md-8',
            }),
        ] + self::control());

        if ($subject === 'document') {
            $builder->add('document_type', ChoiceType::class, [
                'label'   => 'playground.documents.type',
                'choices' => [
                    'playground.documents.option_invoice'  => 'invoice',
                    'playground.documents.option_contract' => 'contract',
                ],
                'row_attr' => $col('col-md-4'),
            ] + self::select());
        }

        if ($subject === 'expense') {
            $builder
                ->add('tenant', ChoiceType::class, [
                    'label'                     => 'playground.expenses.tenant',
                    'choices'                   => ['acme' => 'acme', 'globex' => 'globex'],
                    'choice_translation_domain' => false,
                    'row_attr'                  => $col('col-md-3'),
                ] + self::select())
                ->add('department', ChoiceType::class, [
                    'label'                     => 'playground.expenses.department',
                    'choices'                   => ['finance' => 'finance', 'hr' => 'hr'],
                    'choice_translation_domain' => false,
                    'row_attr'                  => $col('col-md-3'),
                ] + self::select());
        }

        if ($subject === 'purchase_order') {
            $builder
                ->add('tenant', TextType::class, [
                    'label'    => 'playground.purchase_orders.tenant',
                    'required' => false,
                    'row_attr' => $col('col-md-2'),
                ] + self::control())
                ->add('region', ChoiceType::class, [
                    'label'                     => 'playground.purchase_orders.region',
                    'choices'                   => ['eu' => 'eu', 'us' => 'us'],
                    'choice_translation_domain' => false,
                    'row_attr'                  => $col('col-md-3'),
                ] + self::select())
                ->add('amount_tier', ChoiceType::class, [
                    'label'   => 'playground.purchase_orders.amount_tier',
                    'choices' => [
                        'playground.purchase_orders.option_high' => 'high',
                        'playground.purchase_orders.option_low'  => 'low',
                    ],
                    'row_attr' => $col('col-md-2'),
                ] + self::select());
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'translation_domain' => 'demo',
            'attr'               => ['class' => 'card shadow-sm mb-4'],
        ]);
        $resolver->setRequired('subject');
        $resolver->setAllowedValues('subject', self::SUBJECTS);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function control(): array
    {
        return ['label_attr' => ['class' => 'form-label'], 'attr' => ['class' => 'form-control']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function select(): array
    {
        return ['label_attr' => ['class' => 'form-label'], 'attr' => ['class' => 'form-select']];
    }
}
