<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LocaleManagerTest extends TestCase
{
    public function testSetAndResolveEnabledLocale(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);

        $stack = new RequestStack([$request]);

        $manager = new LocaleManager($stack, ['en', 'es'], 'en');
        $manager->setLocale('es');

        self::assertSame('es', $manager->resolveLocale());
        self::assertSame(['en', 'es'], $manager->getEnabledLocales());
        self::assertSame('en', $manager->getDefaultLocale());
        self::assertTrue($manager->isEnabled('es'));
    }

    public function testIgnoresDisabledLocale(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);

        $stack = new RequestStack([$request]);

        $manager = new LocaleManager($stack, ['en'], 'en');
        $manager->setLocale('fr');

        self::assertSame('en', $manager->resolveLocale());
    }

    public function testResolveDefaultWithoutSession(): void
    {
        $manager = new LocaleManager(new RequestStack(), ['en', 'fr'], 'fr');

        self::assertSame('fr', $manager->resolveLocale());
    }
}
