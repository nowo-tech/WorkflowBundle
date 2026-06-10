<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Twig;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes workflow UI globals to Twig templates.
 */
final class WorkflowExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'nowo_workflow_locales' => $this->localeManager->getEnabledLocales(),
        ];
    }
}
