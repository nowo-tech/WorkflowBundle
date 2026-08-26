<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * Host-chosen CSS stack for {@code nowo_workflow.ui.css_framework} (REQ-UI-001 / REQ-PHP-001).
 */
enum CssFramework: string
{
    case Bootstrap  = 'bootstrap';
    case Bootstrap5 = 'bootstrap5';
    case Bootstrap4 = 'bootstrap4';
    case Tailwind   = 'tailwind';
    case Foundation = 'foundation';
    case Custom     = 'custom';
    case Tabler     = 'tabler';
    case None       = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Normalize config aliases ({@see Bootstrap} / {@see Tabler} → {@see Bootstrap5}).
     */
    public function normalized(): self
    {
        return match ($this) {
            self::Bootstrap, self::Tabler => self::Bootstrap5,
            default                       => $this,
        };
    }
}
