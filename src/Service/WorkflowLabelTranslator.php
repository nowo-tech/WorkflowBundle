<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Display labels of places and transitions (since 1.16): the translation of their label key in its domain (default
 * `messages`) in the current locale, falling back to the plain label, then the name, when no key is set, no
 * translator is available or the key has no translation (the translator returns the key itself).
 *
 * Stateless (worker safe): the locale is the translator's, set per request by Symfony.
 */
final readonly class WorkflowLabelTranslator
{
    /** Domain of a label key without a domain. */
    public const DEFAULT_DOMAIN = 'messages';

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public function place(WorkflowPlace $place): string
    {
        return $this->translate($place->getLabelKey(), $place->getLabelDomain(), $place->getDisplayLabel());
    }

    public function transition(WorkflowTransition $transition): string
    {
        return $this->translate($transition->getLabelKey(), $transition->getLabelDomain(), $transition->getDisplayLabel());
    }

    /**
     * The translation of `$key` in `$domain`, or `$fallback`.
     */
    public function translate(?string $key, ?string $domain, string $fallback): string
    {
        if ($key === null || $key === '' || !$this->translator instanceof TranslatorInterface) {
            return $fallback;
        }
        $translated = $this->translator->trans($key, [], $domain ?? self::DEFAULT_DOMAIN);

        return $translated === $key || $translated === '' ? $fallback : $translated;
    }
}
