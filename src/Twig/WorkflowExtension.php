<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Twig;

use Nowo\WorkflowBundle\Service\LocaleManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig globals of the Workflow UI: {@code nowo_workflow_locales} (enabled UI locales, for host locale switchers).
 *
 * Removed in 2.0 (deprecated since 1.9): {@code nowo_workflow_layout_template}, {@code nowo_workflow_css_framework},
 * {@code nowo_workflow_icon_set} and {@code nowo_workflow_form_themes}. The pages extend UiKit scaffolds
 * (REQ-UI-003) and read the layout, CSS stack and form theme from UiKit / FormKit.
 */
final class WorkflowExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * Creates the extension with the locale manager.
     *
     * @param string|null $layoutTemplate deprecated since 2.0, ignored (it fed the removed 1.x globals); removed in 3.0
     * @param string|null $cssFramework deprecated since 2.0, ignored; removed in 3.0
     * @param string|null $iconSet deprecated since 2.0, ignored; removed in 3.0
     * @param string|null $formThemeFramework deprecated since 2.0, ignored; removed in 3.0
     *
     * @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter, constructor.unusedParameter, constructor.unusedParameter (four arguments deprecated since 2.0, ignored, kept for BC until 3.0)
     */
    public function __construct(
        private readonly LocaleManager $localeManager,
        ?string $layoutTemplate = null,
        ?string $cssFramework = null,
        ?string $iconSet = null,
        ?string $formThemeFramework = null,
    ) {
        unset($layoutTemplate, $cssFramework, $iconSet, $formThemeFramework);
    }

    /**
     * Returns the Twig globals of the Workflow UI.
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'nowo_workflow_locales' => $this->localeManager->getEnabledLocales(),
        ];
    }

    /**
     * Symfony form themes of a CSS stack (bootstrap4 / bootstrap5 layouts; none for the other stacks).
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
