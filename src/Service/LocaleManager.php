<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function in_array;
use function is_string;

/**
 * Stores and resolves the active UI locale for workflow screens.
 */
final class LocaleManager
{
    public const SESSION_KEY = '_nowo_workflow_locale';

    /**
     * Creates the manager with the request stack, the enabled UI locales and the default locale.
     *
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly array $enabledLocales,
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * Returns the enabled UI locales.
     *
     * @return list<string>
     */
    public function getEnabledLocales(): array
    {
        return $this->enabledLocales;
    }

    /**
     * Returns the default UI locale.
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Tells whether a locale is one of the enabled UI locales.
     */
    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabledLocales, true);
    }

    /**
     * Stores an enabled locale in the session (ignored otherwise).
     */
    public function setLocale(string $locale): void
    {
        if (!$this->isEnabled($locale)) {
            return;
        }

        $session = $this->getSession();
        if ($session instanceof SessionInterface) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $session->set(self::SESSION_KEY, $locale);
        }
    }

    /**
     * Returns the session locale when enabled, else the default locale.
     */
    public function resolveLocale(): string
    {
        $session = $this->getSession();
        if ($session instanceof SessionInterface) {
            $stored = $session->get(self::SESSION_KEY);
            if (is_string($stored) && $this->isEnabled($stored)) {
                return $stored;
            }
        }

        return $this->defaultLocale;
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
