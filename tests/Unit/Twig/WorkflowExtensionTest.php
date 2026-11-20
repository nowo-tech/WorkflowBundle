<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Twig;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Nowo\WorkflowBundle\Twig\WorkflowExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class WorkflowExtensionTest extends TestCase
{
    #[Test]
    public function testExposesOnlyTheLocalesGlobal(): void
    {
        $extension = new WorkflowExtension(new LocaleManager(new RequestStack(), ['en', 'es', 'fr'], 'en'));

        self::assertSame(['nowo_workflow_locales' => ['en', 'es', 'fr']], $extension->getGlobals());
    }

    /**
     * 2.0: the 1.x constructor arguments that fed the removed globals are still accepted, and ignored (removed in 3.0).
     */
    #[Test]
    public function testTheFormerGlobalArgumentsAreIgnored(): void
    {
        $extension = new WorkflowExtension(
            new LocaleManager(new RequestStack(), ['en'], 'en'),
            'base.html.twig',
            'bootstrap4',
            'bootstrap-icons',
            'bootstrap4',
        );

        self::assertSame(['nowo_workflow_locales' => ['en']], $extension->getGlobals());
    }

    #[Test]
    public function testFormThemesAreScopedPerCssFramework(): void
    {
        self::assertSame(['bootstrap_5_layout.html.twig'], WorkflowExtension::formThemesFor('bootstrap5'));
        self::assertSame(['bootstrap_5_layout.html.twig'], WorkflowExtension::formThemesFor('bootstrap'));
        self::assertSame(['bootstrap_5_layout.html.twig'], WorkflowExtension::formThemesFor('tabler'));
        self::assertSame(['bootstrap_4_layout.html.twig'], WorkflowExtension::formThemesFor('bootstrap4'));
        self::assertSame([], WorkflowExtension::formThemesFor('tailwind'));
        self::assertSame([], WorkflowExtension::formThemesFor('none'));
    }
}
