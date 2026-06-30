<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Twig;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Nowo\WorkflowBundle\Twig\WorkflowExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class WorkflowExtensionTest extends TestCase
{
    public function testExposesLocalesGlobal(): void
    {
        $extension = new WorkflowExtension(new LocaleManager(new RequestStack(), ['en', 'es', 'fr'], 'en'));

        self::assertSame(['nowo_workflow_locales' => ['en', 'es', 'fr']], $extension->getGlobals());
    }
}
