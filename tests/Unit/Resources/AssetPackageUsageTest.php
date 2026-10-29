<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function is_file;
use function preg_match_all;
use function str_ends_with;

/**
 * REQ-ASSETS-004: the bundle templates load their published files through the nowo_workflow asset package, never
 * through hard-coded bundles/nowoworkflow paths.
 */
final class AssetPackageUsageTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views';

    private const PUBLIC_DIR = __DIR__ . '/../../../src/Resources/public';

    public function testTemplatesNeverHardCodeThePublishedBundlePath(): void
    {
        foreach ($this->templates() as $path => $source) {
            self::assertStringNotContainsString('bundles/nowoworkflow', $source, $path);
        }
    }

    public function testEveryNowoWorkflowAssetExistsUnderResourcesPublic(): void
    {
        $found = 0;
        foreach ($this->templates() as $path => $source) {
            preg_match_all("/asset\\('([^']+)',\\s*'nowo_workflow'\\)/", $source, $matches);
            foreach ($matches[1] as $asset) {
                ++$found;
                self::assertTrue(is_file(self::PUBLIC_DIR . '/' . $asset), $path . ': ' . $asset);
            }
        }

        self::assertGreaterThan(0, $found);
    }

    /**
     * @return iterable<string, string>
     */
    private function templates(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $path = $file->getPathname();

                yield $path => (string) file_get_contents($path);
            }
        }
    }
}
