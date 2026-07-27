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
        ], $extension->getGlobals());
    }
}
