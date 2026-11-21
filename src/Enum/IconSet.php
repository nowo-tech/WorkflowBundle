<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * Icon set values of the 1.x {@code nowo_workflow.ui.icon_set} key (removed in 2.0; the set is chosen with
 * {@code nowo_ui_kit.icon_set}), REQ-UI-001 / REQ-PHP-001.
 */
enum IconSet: string
{
    case BootstrapIcons = 'bootstrap-icons';
    case TablerIcons    = 'tabler-icons';
    case UxIcon         = 'ux_icon';
    case SvgInline      = 'svg_inline';
    case None           = 'none';

    /**
     * Returns every accepted icon_set value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
