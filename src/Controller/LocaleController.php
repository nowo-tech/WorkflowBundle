<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;
use function is_string;

#[Route(path: '', name: 'nowo_workflow_')]
final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    #[Route('/locale/{_locale}', name: 'locale_switch', requirements: ['_locale' => 'en|es|fr|it'], methods: ['GET'])]
    public function switch(string $_locale, Request $request): RedirectResponse
    {
        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $this->localeManager->setLocale($_locale);

        $referer = $request->headers->get('referer');
        if (is_string($referer) && self::isSafeRedirectTarget($referer, $request)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('nowo_workflow_dashboard');
    }

    /**
     * Accepts only same-host absolute URLs or local paths (no scheme-relative "//host" or backslash tricks).
     */
    private static function isSafeRedirectTarget(string $target, Request $request): bool
    {
        if ($target === '' || preg_match('/[\\\\\x00-\x1F]/', $target) === 1) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            return !str_starts_with($target, '//');
        }

        $parts = parse_url($target);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && strtolower($parts['host']) === strtolower($request->getHost());
    }
}
