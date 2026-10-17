<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Twig;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Deprecated 1.x Twig globals of the Workflow UI (removed in 2.0): the pages extend UiKit scaffolds (REQ-UI-003)
 * and read the layout, CSS stack and form theme from UiKit / FormKit. Only the deprecated
 * {@code @NowoWorkflowBundle/base.html.twig} bridge reads {@code nowo_workflow_layout_template}.
 */
final class WorkflowExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly LocaleManager $localeManager,
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
        private readonly string $iconSet,
        private readonly ?string $formThemeFramework = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'nowo_workflow_locales'         => $this->localeManager->getEnabledLocales(),
            'nowo_workflow_layout_template' => $this->layoutTemplate,
            'nowo_workflow_css_framework'   => $this->cssFramework,
            'nowo_workflow_icon_set'        => $this->iconSet,
            // Applied to the Workflow forms only while the deprecated ui.css_framework is set (1.x behaviour).
            'nowo_workflow_form_themes' => $this->formThemeFramework === null ? [] : self::formThemesFor($this->formThemeFramework),
        ];
    }

    /**
     * Symfony form themes applied (scoped) to the bundle's own forms for a given ui.css_framework.
     *
     * @return list<string>
     */
    public static function formThemesFor(string $cssFramework): array
    {
        return match ($cssFramework) {
            'bootstrap4'                        => ['bootstrap_4_layout.html.twig'],
            'bootstrap', 'bootstrap5', 'tabler' => ['bootstrap_5_layout.html.twig'],
            default                             => [],
        };
    }
}
