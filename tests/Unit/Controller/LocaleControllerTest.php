<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Nowo\WorkflowBundle\Controller\LocaleController;
use Nowo\WorkflowBundle\Service\LocaleManager;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame('/generated/nowo_workflow_dashboard', $response->headers->get('Location'));
    }

    public function testSwitchAllowsSameHostAbsoluteReferer(): void
    {
        $request = Request::create('http://app.test/workflow/locale/es', 'GET', [], [], [], ['HTTP_REFERER' => 'https://APP.test/workflow/definitions?page=2']);

        $response = $this->controller($request)->switch('es', $request);

        self::assertSame('https://APP.test/workflow/definitions?page=2', $response->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeRefererProvider(): iterable
    {
        yield 'foreign host' => ['https://evil.example/phish'];
        yield 'host suffix' => ['https://app.test.evil.example/'];
        yield 'scheme-relative' => ['//evil.example/phish'];
        yield 'backslash' => ['/\\evil.example/phish'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'relative without slash' => ['evil.example'];
        yield 'control char' => ["/workflow\r\nX-Injected: 1"];
        yield 'userinfo trick' => ['https://app.test@evil.example/'];
        yield 'malformed' => ['http:///'];
    }

    #[DataProvider('unsafeRefererProvider')]
    public function testSwitchRejectsOpenRedirectReferer(string $referer): void
    {
        $request = Request::create('http://app.test/workflow/locale/es', 'GET', [], [], [], ['HTTP_REFERER' => $referer]);

        $response = $this->controller($request)->switch('es', $request);

        self::assertSame('/generated/nowo_workflow_dashboard', $response->headers->get('Location'));
    }

    private function controller(Request $request): LocaleController
    {
        $request->setSession(new Session(new MockArraySessionStorage()));
        $controller = new LocaleController(new LocaleManager(new RequestStack([$request]), ['en', 'es'], 'en'));
        $controller->setContainer(ControllerContainerFactory::create());

        return $controller;
    }
}
