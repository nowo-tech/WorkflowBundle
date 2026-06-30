<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Nowo\WorkflowBundle\Controller\LocaleController;
use Nowo\WorkflowBundle\Service\LocaleManager;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LocaleControllerTest extends TestCase
{
    public function testSwitchRedirectsToReferer(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/workflow', 'GET', [], [], [], ['HTTP_REFERER' => '/workflow/definitions']);
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $localeManager = new LocaleManager($requestStack, ['en', 'es'], 'en');
        $controller    = new LocaleController($localeManager);
        $controller->setContainer(ControllerContainerFactory::create());

        $response = $controller->switch('es', $request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/workflow/definitions', $response->headers->get('Location'));
        self::assertSame('es', $session->get(LocaleManager::SESSION_KEY));
    }

    public function testSwitchRedirectsToRootWhenRefererMissing(): void
    {
        $request       = Request::create('/workflow/locale/en');
        $requestStack  = new RequestStack([$request]);
        $localeManager = new LocaleManager($requestStack, ['en', 'es'], 'en');

        $controller = new LocaleController($localeManager);
        $controller->setContainer(ControllerContainerFactory::create());

        $response = $controller->switch('en', $request);

        self::assertSame('/', $response->headers->get('Location'));
    }
}
