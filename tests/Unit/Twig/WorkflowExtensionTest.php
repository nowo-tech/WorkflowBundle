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
    public function testExposesUiGlobals(): void
    {
        $extension = new WorkflowExtension(
            new LocaleManager(new RequestStack(), ['en', 'es', 'fr'], 'en'),
            '@NowoWorkflowBundle/layout.html.twig',
            'bootstrap5',
            'bootstrap-icons',
        );

        self::assertSame([
            'nowo_workflow_locales'         => ['en', 'es', 'fr'],
            'nowo_workflow_layout_template' => '@NowoWorkflowBundle/layout.html.twig',
            'nowo_workflow_css_framework'   => 'bootstrap5',
            'nowo_workflow_icon_set'        => 'bootstrap-icons',
            'nowo_workflow_form_themes'     => ['bootstrap_5_layout.html.twig'],
        ], $extension->getGlobals());
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
