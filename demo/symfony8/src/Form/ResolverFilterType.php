<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DemoDocument;
use App\Entity\DemoExpense;
use App\Entity\DemoOrder;
use App\Entity\DemoPurchaseOrder;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET form of the resolver playground (REQ-TWIG-005 rule 6, REQ-DEMO-015: FormKit profile `demo`): query names subject_class, document_type, tenant,
 * department, region and amount_tier stay unchanged for bookmarks; no CSRF token.
 *
 * @extends FormKitAbstractType<array<string, string|null>>
 */
#[FormKitConfig('demo')]
final class ResolverFilterType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('subject_class', ChoiceType::class, [
            'label'   => 'playground.resolver.subject_class',
            'choices' => [
                'DemoOrder (default)'          => DemoOrder::class,
                'DemoDocument (1 param)'       => DemoDocument::class,
                'DemoExpense (2 params)'       => DemoExpense::class,
                'DemoPurchaseOrder (3 params)' => DemoPurchaseOrder::class,
            ],
            'choice_translation_domain' => false,
        ]);

        foreach (['document_type', 'tenant', 'department', 'region', 'amount_tier'] as $name) {
            $builder->add($name, TextType::class, [
                'label'              => $name,
                'translation_domain' => false,
                'required'           => false,
            ]);
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'demo',
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
