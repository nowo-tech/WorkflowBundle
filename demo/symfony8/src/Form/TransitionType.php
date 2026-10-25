<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only POST that applies one workflow transition (REQ-TWIG-005). No fields: templates render the CSRF child
 * through the REQ-TWIG-003 child loop and keep the transition button outside the loop.
 *
 * @extends AbstractType<null>
 */
final class TransitionType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['method' => 'POST']);
    }
}
