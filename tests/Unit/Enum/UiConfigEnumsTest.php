<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Enum;

use Nowo\WorkflowBundle\Enum\CssFramework;
use Nowo\WorkflowBundle\Enum\IconSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UiConfigEnumsTest extends TestCase
{
    #[Test]
    public function testCssFrameworkValuesIncludeCanonicalStacks(): void
    {
        $values = CssFramework::values();

        self::assertContains('bootstrap5', $values);
        self::assertContains('bootstrap4', $values);
        self::assertContains('tailwind', $values);
        self::assertContains('foundation', $values);
        self::assertContains('custom', $values);
        self::assertContains('none', $values);
    }

    #[Test]
    public function testCssFrameworkNormalizesBootstrapAndTablerAliases(): void
    {
        self::assertSame(CssFramework::Bootstrap5, CssFramework::Bootstrap->normalized());
        self::assertSame(CssFramework::Bootstrap5, CssFramework::Tabler->normalized());
        self::assertSame(CssFramework::Tailwind, CssFramework::Tailwind->normalized());
        self::assertSame(CssFramework::Bootstrap4, CssFramework::Bootstrap4->normalized());
    }

    #[Test]
    public function testIconSetValues(): void
    {
        self::assertContains('bootstrap-icons', IconSet::values());
        self::assertContains('tabler-icons', IconSet::values());
        self::assertContains('ux_icon', IconSet::values());
        self::assertContains('svg_inline', IconSet::values());
        self::assertContains('none', IconSet::values());
    }
}
