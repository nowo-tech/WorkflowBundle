<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DemoDocument;
use App\Entity\DemoExpense;
use App\Entity\DemoOrder;
use App\Entity\DemoPurchaseOrder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET form of the resolver playground (REQ-TWIG-005 rule 6): query names subject_class, document_type, tenant,
 * department, region and amount_tier stay unchanged for bookmarks; no CSRF token.
 *
 * @extends AbstractType<array<string, string|null>>
 */
final class ResolverFilterType extends AbstractType
{
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
            'row_attr'                  => ['class' => 'col-md-6'],
            'label_attr'                => ['class' => 'form-label'],
            'attr'                      => ['class' => 'form-select'],
        ]);

        foreach (['document_type' => 'col-md-6', 'tenant' => 'col-md-4', 'department' => 'col-md-4', 'region' => 'col-md-4', 'amount_tier' => 'col-md-4'] as $name => $class) {
            $builder->add($name, TextType::class, [
                'label'              => $name,
                'translation_domain' => false,
                'required'           => false,
                'row_attr'           => ['class' => $class],
                'label_attr'         => ['class' => 'form-label'],
                'attr'               => ['class' => 'form-control'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'demo',
            'attr'               => ['class' => 'card shadow-sm mb-4'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
