<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\FormTypeInterface;

use function basename;
use function class_exists;
use function class_uses;
use function dirname;
use function glob;
use function is_subclass_of;

/**
 * REQ-TWIG-005 rule 11: every form type of the bundle (page forms and the embedded place multiselect) is a FormKit
 * Type on the `workflow` profile.
 */
final class FormKitIntegrationTest extends TestCase
{
    public function testEveryFormTypeIsAFormKitTypeOnTheBundleProfile(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Form/*Type.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $class = 'Nowo\\WorkflowBundle\\Form\\' . basename($file, '.php');
            self::assertTrue(class_exists($class), $class);
            self::assertTrue(is_subclass_of($class, FormTypeInterface::class), $class);

            $attributes = (new ReflectionClass($class))->getAttributes(FormKitConfig::class);
            self::assertCount(1, $attributes, $class . ' must carry #[FormKitConfig].');
            self::assertSame('workflow', $attributes[0]->newInstance()->name, $class);
            self::assertContains(FormOptionsTrait::class, class_uses($class), $class . ' must use FormOptionsTrait.');
        }
    }
}
