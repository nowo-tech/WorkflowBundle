<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\EventListener;

use Nowo\WorkflowBundle\EventListener\LocaleSubscriber;
use Nowo\WorkflowBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriberTest extends TestCase
{
    public function testSetsLocaleOnMainRequest(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_nowo_workflow_locale', 'es');
        $request = Request::create('/');
        $request->setSession($session);

        $stack = new RequestStack([$request]);

        $manager = new LocaleManager($stack, ['en', 'es'], 'en');
        $event   = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new LocaleSubscriber($manager))->onKernelRequest($event);

        self::assertSame('es', $request->getLocale());
    }

    public function testIgnoresSubRequest(): void
    {
        $request = Request::create('/');
        $event   = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        (new LocaleSubscriber(new LocaleManager(new RequestStack(), ['en'], 'en')))->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
    }

    public function testSubscribedEvents(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 20]],
            LocaleSubscriber::getSubscribedEvents(),
        );
    }
}
