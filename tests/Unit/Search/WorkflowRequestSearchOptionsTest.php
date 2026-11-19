<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Search;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class WorkflowRequestSearchOptionsTest extends TestCase
{
    private const REQUIRED = ['subject_class' => 'App\Leave\LeaveRequest', 'route' => 'leave_show', 'gate' => 'app.gate', 'title_field' => 'title', 'tenant_field' => 'organizationId', 'accept_window_side_channel' => true];

    #[Test]
    public function defaultsAreSafe(): void
    {
        $options = WorkflowRequestSearchOptions::fromConfig('leave', self::REQUIRED);

        self::assertSame('leave', $options->subject);
        self::assertSame('App\Leave\LeaveRequest', $options->subjectClass);
        self::assertSame('leave_show', $options->route);
        self::assertSame('app.gate', $options->gate);
        self::assertSame('workflow_leave', $options->groupKey());
        self::assertNull($options->titleResolver);
        self::assertNull($options->narrower);
        self::assertSame(['id', null, 'title', 'status'], [$options->idField, $options->searchTextField, $options->titleField, $options->statusField]);
        self::assertSame('title', $options->searchField(), 'without a search text the title is searched');
        self::assertSame('organizationId', $options->tenantField);
        self::assertFalse($options->singleTenant);
        self::assertNull($options->archivedField);
        self::assertNull($options->orderField);
        self::assertSame([], $options->excludedStatuses);
        self::assertSame('id', $options->routeIdParameter);
        self::assertSame([], $options->routeParameters);
        self::assertNull($options->seeAllRoute);
        self::assertSame([], $options->seeAllParameters);
        self::assertSame(WorkflowRequestSearchOptions::DEFAULT_LABEL, $options->label);
        self::assertNull($options->labelDomain);
        self::assertNull($options->statusLabelDomain);
        self::assertSame('', $options->statusLabelPrefix);
        self::assertNull($options->icon);
        self::assertTrue($options->sensitive, 'titles are private by default');
        self::assertSame(100, $options->candidates());
        self::assertSame(0, $options->minLength);
        self::assertSame(0, $options->priority);
        self::assertTrue($options->acceptWindowSideChannel);
        self::assertSame(10000, $options->narrowerMaxValues);
        self::assertSame('workflow_leave', $options->subjectType());
    }

    #[Test]
    public function everyOptionIsRead(): void
    {
        $options = WorkflowRequestSearchOptions::fromConfig('leave', [
            'subject_class'       => 'App\Leave\LeaveRequest',
            'route'               => 'leave_show',
            'gate'                => 'app.gate',
            'group'               => 'workflow_leave_requests',
            'title_resolver'      => 'app.titles',
            'narrower'            => 'app.narrower',
            'id_field'            => 'uuid',
            'search_text_field'   => 'searchText',
            'status_field'        => 'workflowPlace',
            'single_tenant'       => true,
            'archived_field'      => 'deletedAt',
            'order_field'         => 'updatedAt',
            'excluded_statuses'   => ['draft', 7, 'cancelled'],
            'route_id_parameter'  => 'uuid',
            'route_parameters'    => ['area' => 'leave', 0 => 'ignored', 'n' => 1],
            'see_all_route'       => 'leave_index',
            'see_all_parameters'  => ['tab' => 'mine'],
            'label'               => 'search.leave',
            'label_domain'        => 'messages',
            'status_label_domain' => 'messages',
            'status_label_prefix' => 'leave.status.',
            'icon'                => 'tabler:beach',
            'sensitive'           => false,
            'max_candidates'      => 50,
            'min_length'          => 2,
            'priority'            => 40,
            'narrower_max_values' => 2500,
        ]);

        self::assertSame('workflow_leave_requests', $options->groupKey());
        self::assertSame(['app.titles', 'app.narrower'], [$options->titleResolver, $options->narrower]);
        self::assertSame(['uuid', 'searchText', null, 'workflowPlace', null, 'deletedAt', 'updatedAt'], [$options->idField, $options->searchTextField, $options->titleField, $options->statusField, $options->tenantField, $options->archivedField, $options->orderField]);
        self::assertSame('searchText', $options->searchField());
        self::assertTrue($options->singleTenant);
        self::assertSame(['draft', 'cancelled'], $options->excludedStatuses);
        self::assertSame('uuid', $options->routeIdParameter);
        self::assertSame(['area' => 'leave'], $options->routeParameters);
        self::assertSame('leave_index', $options->seeAllRoute);
        self::assertSame(['tab' => 'mine'], $options->seeAllParameters);
        self::assertSame(['search.leave', 'messages'], [$options->label, $options->labelDomain]);
        self::assertSame('messages', $options->statusLabelDomain);
        self::assertSame('leave.status.', $options->statusLabelPrefix);
        self::assertSame('tabler:beach', $options->icon);
        self::assertFalse($options->sensitive);
        self::assertSame(50, $options->candidates());
        self::assertSame(2, $options->minLength);
        self::assertSame(40, $options->priority);
        self::assertFalse($options->acceptWindowSideChannel);
        self::assertSame(2500, $options->narrowerMaxValues);
    }

    #[Test]
    public function missingRequiredOptionsAreNamed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subjects.leave: set subject_class, route, gate');

        WorkflowRequestSearchOptions::fromConfig('leave', ['subject_class' => '', 'route' => null]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'group with a capital' => [['group' => 'Requests'], 'group is not valid'];
        yield 'group starting with a digit' => [['group' => '1requests'], 'group is not valid'];
        yield 'reserved group' => [['group' => 'nowo_search_requests'], 'must not start with "nowo_search_"'];
        yield 'field with a dot' => [['title_field' => 'r.title'], 'title_field is not valid'];
        yield 'field with SQL' => [['tenant_field' => 'id OR 1=1'], 'tenant_field is not valid'];
        yield 'field with a newline' => [['status_field' => "status\n"], 'status_field is not valid'];
        yield 'nothing searched or shown' => [['title_field' => null], 'needs search_text_field or title_field'];
        yield 'nothing shown' => [['title_field' => null, 'search_text_field' => 'searchText'], 'needs title_resolver or title_field'];
        yield 'no tenant' => [['tenant_field' => null], 'needs tenant_field, or single_tenant: true'];
        yield 'both tenant modes' => [['single_tenant' => true], 'sets both tenant_field and single_tenant'];
        yield 'route with a space' => [['route' => 'leave show'], 'route is not valid'];
        yield 'route parameter name' => [['route_parameters' => ['a b' => 'x']], 'route parameter is not valid'];
        yield 'see-all parameter name' => [['see_all_parameters' => ['a b' => 'x']], 'route parameter is not valid'];
        yield 'label with a space' => [['label' => 'a b'], 'label is not valid'];
        yield 'empty excluded status' => [['excluded_statuses' => ['']], 'must not hold an empty status'];
        yield 'too many candidates' => [['max_candidates' => 1001], 'max_candidates must be 1 to 1000'];
        yield 'no candidates' => [['max_candidates' => 0], 'max_candidates'];
        yield 'negative min length' => [['min_length' => -1], 'min_length'];
        yield 'no narrower values' => [['narrower_max_values' => 0], 'narrower_max_values must be 1 to 60000'];
        yield 'too many narrower values' => [['narrower_max_values' => 60001], 'narrower_max_values'];
        yield 'sensitive without a narrower' => [['accept_window_side_channel' => false], 'is sensitive: set a narrower'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidValuesAreRefused(array $override, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        WorkflowRequestSearchOptions::fromConfig('leave', $override + self::REQUIRED);
    }

    #[Test]
    public function anInvalidSubjectKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subjects: key is not valid');

        WorkflowRequestSearchOptions::fromConfig('Leave-Requests', self::REQUIRED);
    }

    #[Test]
    public function aSubjectKeyLeavesRoomForTheSubjectType(): void
    {
        $longest = 'a' . str_repeat('b', 54);
        self::assertSame('workflow_' . $longest, WorkflowRequestSearchOptions::fromConfig($longest, self::REQUIRED + ['group' => 'long'])->subjectType());

        $this->expectException(InvalidArgumentException::class);
        WorkflowRequestSearchOptions::fromConfig($longest . 'c', self::REQUIRED + ['group' => 'long']);
    }

    #[Test]
    public function aSensitiveSubjectWithANarrowerOrNotSensitiveNeedsNoAcceptance(): void
    {
        $base = ['accept_window_side_channel' => false] + self::REQUIRED;

        self::assertSame('app.narrower', WorkflowRequestSearchOptions::fromConfig('leave', ['narrower' => 'app.narrower'] + $base)->narrower);
        self::assertFalse(WorkflowRequestSearchOptions::fromConfig('leave', ['sensitive' => false] + $base)->sensitive);
    }

    #[Test]
    public function anEmptyClassRouteOrGateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs subject_class, route and gate');

        new WorkflowRequestSearchOptions('leave', '', 'leave_show', 'app.gate', titleField: 'title', singleTenant: true, acceptWindowSideChannel: true);
    }
}
