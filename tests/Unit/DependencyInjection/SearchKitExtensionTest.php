<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;

/**
 * Since 1.21.0: `nowo_workflow.search_kit` — the group is registered only when enabled and SearchKit is installed.
 */
final class SearchKitExtensionTest extends TestCase
{
    private const ENABLED = [
        'enabled'       => true,
        'subject_class' => 'App\Leave\LeaveRequest',
        'route'         => 'leave_show',
        'gate'          => 'app.leave_gate',
        'priority'      => 30,
        'tenant_field'  => 'organizationId',
    ];

    #[Test]
    public function offByDefault(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasDefinition(WorkflowRequestSearchDefinition::class));
        self::assertFalse($container->hasDefinition(WorkflowRequestSearchOptions::class));
    }

    #[Test]
    public function enabledRegistersTheTaggedDefinitionWhenSearchKitIsInstalled(): void
    {
        $container = $this->load(self::ENABLED);
        if (!class_exists(NowoWorkflowExtension::SEARCH_KIT_DEFINITION)) {
            self::assertFalse($container->hasDefinition(WorkflowRequestSearchDefinition::class), 'SearchKit is optional');

            return;
        }

        $definition = $container->getDefinition(WorkflowRequestSearchDefinition::class);
        self::assertSame([['priority' => 30]], $definition->getTag(NowoWorkflowExtension::SEARCH_KIT_DEFINITION_TAG));
        $gate = $definition->getArgument(1);
        self::assertInstanceOf(Reference::class, $gate);
        self::assertSame('app.leave_gate', (string) $gate);

        $options = $container->getDefinition(WorkflowRequestSearchOptions::class);
        self::assertSame([WorkflowRequestSearchOptions::class, 'fromConfig'], $options->getFactory());
        $config = $options->getArgument(0);
        self::assertIsArray($config);
        self::assertSame('organizationId', $config['tenant_field']);
        self::assertSame('workflow_requests', $config['group']);
        self::assertTrue($config['sensitive']);
    }

    #[Test]
    public function enabledWithoutTheRequiredOptionsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('set subject_class, route, gate');

        $this->load(['enabled' => true]);
    }

    #[Test]
    public function anInvalidFieldIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('title_field is not valid');

        $this->load(['title_field' => 'title) OR (1=1'] + self::ENABLED);
    }

    /**
     * @param array<string, mixed> $searchKit
     */
    private function load(array $searchKit): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->getExtension('nowo_workflow')->load([['runtime' => ['flush_after_apply' => false], 'search_kit' => $searchKit]], $container);

        return $container;
    }
}
