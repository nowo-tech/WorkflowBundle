<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only POST that applies one workflow transition (REQ-TWIG-005, REQ-DEMO-015: FormKit profile `demo`). No fields: templates render the CSRF child
 * through the REQ-TWIG-003 child loop and keep the transition button outside the loop.
 *
 * @extends FormKitAbstractType<null>
 */
#[FormKitConfig('demo')]
final class TransitionType extends FormKitAbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['method' => 'POST']);
    }
}
