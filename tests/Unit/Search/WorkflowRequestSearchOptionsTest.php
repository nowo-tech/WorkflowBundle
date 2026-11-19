<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Search;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRequestSearchOptionsTest extends TestCase
{
    private const REQUIRED = ['subject_class' => 'App\Leave\LeaveRequest', 'route' => 'leave_show', 'gate' => 'app.gate'];

    #[Test]
    public function defaultsAreSafe(): void
    {
        $options = WorkflowRequestSearchOptions::fromConfig(self::REQUIRED);

        self::assertSame('App\Leave\LeaveRequest', $options->subjectClass);
        self::assertSame('leave_show', $options->route);
        self::assertSame('workflow_requests', $options->group);
        self::assertSame(['id', 'title', 'status'], [$options->idField, $options->titleField, $options->statusField]);
        self::assertNull($options->tenantField);
        self::assertNull($options->archivedField);
        self::assertNull($options->orderField);
        self::assertSame([], $options->excludedStatuses);
        self::assertSame('id', $options->routeIdParameter);
        self::assertSame([], $options->routeParameters);
        self::assertNull($options->seeAllRoute);
        self::assertSame([], $options->seeAllParameters);
        self::assertNull($options->statusLabelDomain);
        self::assertSame('', $options->statusLabelPrefix);
        self::assertNull($options->icon);
        self::assertTrue($options->sensitive, 'titles are private by default');
        self::assertSame(100, $options->candidates());
        self::assertSame(0, $options->minLength);
    }

    #[Test]
    public function everyOptionIsRead(): void
    {
        $options = WorkflowRequestSearchOptions::fromConfig(self::REQUIRED + [
            'group'               => 'leave_requests',
            'id_field'            => 'uuid',
            'title_field'         => 'summary',
            'status_field'        => 'workflowPlace',
            'tenant_field'        => 'organizationId',
            'archived_field'      => 'deletedAt',
            'order_field'         => 'updatedAt',
            'excluded_statuses'   => ['draft', 7, 'cancelled'],
            'route_id_parameter'  => 'uuid',
            'route_parameters'    => ['area' => 'leave', 0 => 'ignored', 'n' => 1],
            'see_all_route'       => 'leave_index',
            'see_all_parameters'  => ['tab' => 'mine'],
            'status_label_domain' => 'messages',
            'status_label_prefix' => 'leave.status.',
            'icon'                => 'tabler:beach',
            'sensitive'           => false,
            'max_candidates'      => 50,
            'min_length'          => 2,
        ]);

        self::assertSame('leave_requests', $options->group);
        self::assertSame(['uuid', 'summary', 'workflowPlace', 'organizationId', 'deletedAt', 'updatedAt'], [$options->idField, $options->titleField, $options->statusField, $options->tenantField, $options->archivedField, $options->orderField]);
        self::assertSame(['draft', 'cancelled'], $options->excludedStatuses);
        self::assertSame('uuid', $options->routeIdParameter);
        self::assertSame(['area' => 'leave'], $options->routeParameters);
        self::assertSame('leave_index', $options->seeAllRoute);
        self::assertSame(['tab' => 'mine'], $options->seeAllParameters);
        self::assertSame('messages', $options->statusLabelDomain);
        self::assertSame('leave.status.', $options->statusLabelPrefix);
        self::assertSame('tabler:beach', $options->icon);
        self::assertFalse($options->sensitive);
        self::assertSame(50, $options->candidates());
        self::assertSame(2, $options->minLength);
    }

    #[Test]
    public function missingRequiredOptionsAreNamed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('set subject_class, route, gate');

        WorkflowRequestSearchOptions::fromConfig(['subject_class' => '', 'route' => null]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'group with a capital' => [['group' => 'Requests']];
        yield 'group starting with a digit' => [['group' => '1requests']];
        yield 'field with a dot' => [['title_field' => 'r.title']];
        yield 'field with SQL' => [['tenant_field' => 'id OR 1=1']];
        yield 'field with a newline' => [['status_field' => "status\n"]];
        yield 'route with a space' => [['route' => 'leave show']];
        yield 'route parameter name' => [['route_parameters' => ['a b' => 'x']]];
        yield 'empty excluded status' => [['excluded_statuses' => ['']]];
        yield 'too many candidates' => [['max_candidates' => 1001]];
        yield 'no candidates' => [['max_candidates' => 0]];
        yield 'negative min length' => [['min_length' => -1]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidValuesAreRefused(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkflowRequestSearchOptions::fromConfig($override + self::REQUIRED);
    }

    #[Test]
    public function anEmptySubjectClassIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkflowRequestSearchOptions('', 'leave_show');
    }
}
