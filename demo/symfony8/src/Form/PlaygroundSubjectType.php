<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function in_array;

/**
 * "Create" form of each workflow playground page (REQ-TWIG-005, REQ-DEMO-015: FormKit profile `demo`). The subject option picks the fields; posted names
 * stay flat (title, document_type, tenant, department, region, amount_tier) as the demo controller reads them.
 * CSRF uses the demo's stateless "submit" token id (config/packages/csrf.yaml).
 *
 * @extends FormKitAbstractType<array<string, string|null>>
 */
#[FormKitConfig('demo')]
final class PlaygroundSubjectType extends FormKitAbstractType
{
    public const SUBJECTS = ['order', 'change_request', 'document', 'expense', 'purchase_order'];

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $subject = $options['subject'];

        $builder->add('title', TextType::class, [
            'label'    => 'playground.orders.title_field',
            'required' => in_array($subject, ['order', 'change_request', 'document'], true),
        ]);

        if ($subject === 'document') {
            $builder->add('document_type', ChoiceType::class, [
                'label'   => 'playground.documents.type',
                'choices' => [
                    'playground.documents.option_invoice'  => 'invoice',
                    'playground.documents.option_contract' => 'contract',
                ],
            ]);
        }

        if ($subject === 'expense') {
            $builder
                ->add('tenant', ChoiceType::class, [
                    'label'                     => 'playground.expenses.tenant',
                    'choices'                   => ['acme' => 'acme', 'globex' => 'globex'],
                    'choice_translation_domain' => false,
                ])
                ->add('department', ChoiceType::class, [
                    'label'                     => 'playground.expenses.department',
                    'choices'                   => ['finance' => 'finance', 'hr' => 'hr'],
                    'choice_translation_domain' => false,
                ]);
        }

        if ($subject === 'purchase_order') {
            $builder
                ->add('tenant', TextType::class, [
                    'label'    => 'playground.purchase_orders.tenant',
                    'required' => false,
                ])
                ->add('region', ChoiceType::class, [
                    'label'                     => 'playground.purchase_orders.region',
                    'choices'                   => ['eu' => 'eu', 'us' => 'us'],
                    'choice_translation_domain' => false,
                ])
                ->add('amount_tier', ChoiceType::class, [
                    'label'   => 'playground.purchase_orders.amount_tier',
                    'choices' => [
                        'playground.purchase_orders.option_high' => 'high',
                        'playground.purchase_orders.option_low'  => 'low',
                    ],
                ]);
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'translation_domain' => 'demo',
        ]);
        $resolver->setRequired('subject');
        $resolver->setAllowedValues('subject', self::SUBJECTS);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
