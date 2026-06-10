<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    #[Route('/locale/{_locale}', name: 'demo_locale_switch', requirements: ['_locale' => 'en|es|fr|it'], methods: ['GET'])]
    public function switch(string $_locale, Request $request): Response
    {
        $this->localeManager->setLocale($_locale);

        $referer = $request->headers->get('referer');

        return $this->redirect($referer !== null && $referer !== '' ? $referer : '/');
    }
}
