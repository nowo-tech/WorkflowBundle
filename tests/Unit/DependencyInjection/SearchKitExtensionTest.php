<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Nowo\WorkflowBundle\Tests\Support\SearchKit110DefinitionStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;

/**
 * Since 1.21.0: `nowo_workflow.search_kit.subjects` — one group per subject, registered only when enabled and
 * SearchKit is installed; everything validated when the container is built.
 */
final class SearchKitExtensionTest extends TestCase
{
    private const LEAVE = [
        'subject_class'     => 'App\Leave\LeaveRequest',
        'route'             => 'leave_show',
        'gate'              => 'app.leave_gate',
        'title_resolver'    => 'app.leave_titles',
        'narrower'          => 'app.leave_narrower',
        'search_text_field' => 'searchText',
        'tenant_field'      => 'organizationId',
        'priority'          => 30,
    ];

    private const PURCHASE = [
        'subject_class' => 'App\Purchase\PurchaseRequest',
        'route'         => 'purchase_show',
        'gate'          => 'app.purchase_gate',
        'narrower'      => 'app.purchase_narrower',
        'title_field'   => 'title',
        'single_tenant' => true,
        'sensitive'     => false,
    ];

    #[Test]
    public function offByDefault(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasDefinition(NowoWorkflowExtension::SEARCH_KIT_SERVICE_PREFIX . 'leave'));
        $config = $this->load(['subjects' => ['leave' => self::LEAVE]]);
        self::assertFalse($config->hasDefinition(NowoWorkflowExtension::SEARCH_KIT_SERVICE_PREFIX . 'leave'), 'subjects without enabled register nothing');
    }

    #[Test]
    public function oneTaggedDefinitionPerSubjectWhenSearchKitIsInstalled(): void
    {
        $container = $this->load(['enabled' => true, 'subjects' => ['leave' => self::LEAVE, 'purchase' => self::PURCHASE]]);
        $leaveId   = NowoWorkflowExtension::SEARCH_KIT_SERVICE_PREFIX . 'leave';
        if (!NowoWorkflowExtension::supportsSearchKit()) {
            self::assertFalse($container->hasDefinition($leaveId), 'SearchKit is optional');

            return;
        }

        $leave = $container->getDefinition($leaveId);
        self::assertSame(WorkflowRequestSearchDefinition::class, $leave->getClass());
        self::assertSame([['priority' => 30]], $leave->getTag(NowoWorkflowExtension::SEARCH_KIT_DEFINITION_TAG));
        self::assertSame('app.leave_gate', (string) $this->reference($leave->getArgument(1)));
        self::assertSame('app.leave_titles', (string) $this->reference($leave->getArgument(6)));
        self::assertSame('app.leave_narrower', (string) $this->reference($leave->getArgument(7)));

        $purchase = $container->getDefinition(NowoWorkflowExtension::SEARCH_KIT_SERVICE_PREFIX . 'purchase');
        self::assertSame([['priority' => 0]], $purchase->getTag(NowoWorkflowExtension::SEARCH_KIT_DEFINITION_TAG));
        self::assertNull($purchase->getArgument(6));
        self::assertSame('app.purchase_narrower', (string) $this->reference($purchase->getArgument(7)));

        $options = $container->getDefinition($leaveId . '.options');
        self::assertSame(WorkflowRequestSearchOptions::class, $options->getClass());
        self::assertSame([WorkflowRequestSearchOptions::class, 'fromConfig'], $options->getFactory());
        self::assertSame('leave', $options->getArgument(0));
        $config = $options->getArgument(1);
        self::assertIsArray($config);
        self::assertSame('organizationId', $config['tenant_field']);
        self::assertTrue($config['sensitive']);
    }

    #[Test]
    public function onlyTheSearchKitProviderBaseOf110IsSupported(): void
    {
        self::assertFalse(NowoWorkflowExtension::supportsSearchKit('Nowo\\Missing\\EntitySearchDefinition'), 'not installed');
        self::assertFalse(NowoWorkflowExtension::supportsSearchKit(stdClass::class), 'no prepareItems(): SearchKit 1.9');
        self::assertTrue(NowoWorkflowExtension::supportsSearchKit(SearchKit110DefinitionStub::class));
        self::assertSame(class_exists(NowoWorkflowExtension::SEARCH_KIT_DEFINITION), NowoWorkflowExtension::supportsSearchKit(), 'the installed SearchKit (1.10+ or none)');
    }

    #[Test]
    public function aSensitiveSubjectWithoutANarrowerIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('subjects.leave is sensitive: set a narrower');

        $this->load(['enabled' => true, 'subjects' => ['leave' => ['narrower' => null] + self::LEAVE]]);
    }

    #[Test]
    public function enabledWithoutSubjectsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('add at least one entry under subjects');

        $this->load(['enabled' => true]);
    }

    #[Test]
    public function aSubjectWithoutTheRequiredOptionsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('subjects.leave: set subject_class, route, gate');

        $this->load(['enabled' => true, 'subjects' => ['leave' => ['title_field' => 'title']]]);
    }

    #[Test]
    public function anInvalidFieldIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('search_text_field is not valid');

        $this->load(['enabled' => true, 'subjects' => ['leave' => ['search_text_field' => 'text) OR (1=1'] + self::LEAVE]]);
    }

    #[Test]
    public function twoSubjectsCannotShareAGroup(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('the subjects "leave" and "purchase" both use the group "workflow_requests"');

        $this->load(['enabled' => true, 'subjects' => [
            'leave'    => ['group' => 'workflow_requests'] + self::LEAVE,
            'purchase' => ['group' => 'workflow_requests'] + self::PURCHASE,
        ]]);
    }

    private function reference(mixed $argument): Reference
    {
        self::assertInstanceOf(Reference::class, $argument);

        return $argument;
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
