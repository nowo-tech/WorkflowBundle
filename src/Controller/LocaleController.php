<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '', name: 'nowo_workflow_')]
final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    #[Route('/locale/{_locale}', name: 'locale_switch', requirements: ['_locale' => 'en|es|fr|it'], methods: ['GET'])]
    public function switch(string $_locale, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->localeManager->setLocale($_locale);

        $referer = $request->headers->get('referer');

        return $this->redirect($referer !== null && $referer !== '' ? $referer : '/');
    }
}
