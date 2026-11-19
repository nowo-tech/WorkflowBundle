<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequest;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequestHarness;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequestStatus;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function array_map;
use function bin2hex;
use function class_exists;
use function mb_strlen;
use function random_bytes;
use function str_repeat;

/**
 * Since 1.21.0: the `workflow_requests` group beyond SearchKit's contract kit — status search, excluded statuses,
 * the access event, what a result shows (never the body or the approver note), labels, links and recents ids.
 */
final class WorkflowRequestSearchDefinitionTest extends TestCase
{
    private const TENANT = 7201;

    private const VIEWER = 'alice@example.com';

    private SearchRequestHarness $harness;

    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
        $this->harness               = new SearchRequestHarness();
        $this->harness->gate->tenant = self::TENANT;
        $this->harness->gate->viewer = self::VIEWER;
    }

    #[Test]
    public function findsRequestsByTitleAndByStatusOnlyThroughTheirFields(): void
    {
        $marker   = $this->marker();
        $pending  = $this->harness->insert(self::TENANT, 'Holiday ' . $marker, body: 'secret-body ' . $marker);
        $approved = $this->harness->insert(self::TENANT, 'Trip ' . $marker, status: SearchRequestStatus::Approved);
        $this->harness->insert(self::TENANT, 'Draft ' . $marker, status: SearchRequestStatus::Draft);
        $this->harness->insert(self::TENANT, 'Archived ' . $marker, archived: true);
        $this->harness->insert(self::TENANT + 1, 'Elsewhere ' . $marker);
        $this->harness->insert(self::TENANT, 'Private ' . $marker, owner: 'bob@example.com');
        $mine = $this->harness->insert(self::TENANT, 'Mine ' . $marker, owner: self::VIEWER);

        self::assertSame(['/requests/' . $pending, '/requests/' . $approved, '/requests/' . $mine], $this->urls($marker));
        self::assertSame(['/requests/' . $approved], $this->urls($marker . ' approved'));
        self::assertSame(['/requests/' . $pending, '/requests/' . $mine], $this->urls($marker . ' PEND'));
        self::assertSame([], $this->urls('secret-body'), 'the body is never searched');
        self::assertSame([], $this->urls('approver note'), 'nor the approver note');
        self::assertSame([], $this->urls($marker . ' draft'), 'excluded statuses are filtered in SQL');
        self::assertSame([[self::TENANT, self::VIEWER]], [$this->harness->gate->enabledCalls[0]]);
    }

    #[Test]
    public function oneAccessEventCarriesIdsAndStatusesOnly(): void
    {
        $marker = $this->marker();
        $first  = $this->harness->insert(self::TENANT, 'A ' . $marker);
        $second = $this->harness->insert(self::TENANT, 'B ' . $marker, status: SearchRequestStatus::Approved);
        /** @var list<WorkflowRequestAccessCheckEvent> $events */
        $events = [];
        $this->harness->dispatcher->addListener(WorkflowRequestAccessCheckEvent::class, static function (WorkflowRequestAccessCheckEvent $event) use (&$events, $second): void {
            $events[] = $event;
            $event->deny((string) $second);
        }, -10);

        self::assertSame(['/requests/' . $first], $this->urls($marker), 'a host deny wins over the owner rule');
        self::assertCount(1, $events);
        self::assertSame(SearchRequest::class, $events[0]->subjectClass);
        self::assertSame(self::VIEWER, $events[0]->userIdentifier);
        self::assertSame(self::TENANT, $events[0]->tenant);
        self::assertSame([[(string) $first, 'pending'], [(string) $second, 'approved']], array_map(static fn (WorkflowRequestRef $r): array => [$r->id, $r->status], $events[0]->requests()));
    }

    #[Test]
    public function nothingIsVisibleWithoutAListenerOrADispatcher(): void
    {
        $marker = $this->marker();
        $this->harness->insert(self::TENANT, 'A ' . $marker);
        $definition = $this->harness->definition(withDispatcher: false);

        self::assertNull((new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker)));
        self::assertSame([], $definition->filterVisible([], self::TENANT, $this->query($marker)));

        $empty               = new SearchRequestHarness();
        $empty->gate->tenant = self::TENANT;
        $empty->gate->viewer = self::VIEWER;
        $empty->insert(self::TENANT, 'A ' . $marker);
        $definition = new WorkflowRequestSearchDefinition(new TestManagerRegistry($empty->em), $empty->gate, SearchRequestHarness::urls(), SearchRequestHarness::options(), new EventDispatcher());
        self::assertNull((new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker)), 'no listener: deny by default');
    }

    #[Test]
    public function anItemShowsTheTitleTheStatusAndTheRouteOnly(): void
    {
        $marker     = $this->marker();
        $id         = $this->harness->insert(self::TENANT, str_repeat('x', 180) . ' ' . $marker . str_repeat('y', 9), body: 'secret');
        $nullId     = $this->harness->insert(self::TENANT, null, status: SearchRequestStatus::Approved);
        $definition = $this->harness->definition(SearchRequestHarness::options(['route_id_parameter' => 'id', 'route_parameters' => ['tab' => 'details']]));
        $query      = $this->query($marker);

        $item = $definition->toItem($this->find($id), $query);
        self::assertSame(WorkflowRequestSearchDefinition::MAX_TITLE_LENGTH, mb_strlen($item->title));
        self::assertSame('/requests/' . $id . '?tab=details', $item->url);
        self::assertSame('pending', $item->subtitle);
        self::assertSame('', $definition->toItem($this->find($nullId), $query)->title);
        self::assertSame('Requests', $definition->label($query));
        self::assertSame((string) $id, $definition->subjectId($this->find($id)));
    }

    #[Test]
    public function labelsAreTranslated(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['search.group.workflow_requests' => 'Solicitudes'], 'es', WorkflowRequestSearchDefinition::TRANSLATION_DOMAIN);
        $translator->addResource('array', ['leave.status.pending' => 'Pendiente'], 'es', 'leave');
        $id    = $this->harness->insert(self::TENANT, 'A');
        $query = new SearchQuery('a', null, 10, userIdentifier: self::VIEWER, locale: 'es');

        $translated = $this->harness->definition(SearchRequestHarness::options(['status_label_domain' => 'leave', 'status_label_prefix' => 'leave.status.']), $translator);
        self::assertSame('Solicitudes', $translated->label($query));
        self::assertSame('Pendiente', $translated->toItem($this->find($id), $query)->subtitle);

        $noTranslator = $this->harness->definition(SearchRequestHarness::options(['status_label_domain' => 'leave']));
        self::assertSame('pending', $noTranslator->toItem($this->find($id), $query)->subtitle);
    }

    #[Test]
    public function hooksFollowTheOptions(): void
    {
        $definition = $this->harness->definition(SearchRequestHarness::options(['group' => 'leave_requests', 'icon' => 'tabler:beach', 'min_length' => 2, 'sensitive' => false, 'id_field' => 'id']));

        self::assertSame('leave_requests', $definition->group());
        self::assertSame('tabler:beach', $definition->icon());
        self::assertSame('workflow_request', $definition->subjectType());
        self::assertSame(['r.title', 'r.status'], $definition->searchableFields());
        self::assertSame(2, $definition->minLength());
        self::assertSame(20, $definition->maxCandidates());
        self::assertFalse($definition->sensitive());
        self::assertSame('r.id', $definition->idField());
        self::assertNull($definition->tenant($this->query('a', 'mallory')), 'the gate decides');
        self::assertSame(self::TENANT, $definition->tenant($this->query('a')));
    }

    #[Test]
    public function seeAllLinksToTheListWithTheText(): void
    {
        self::assertSame('/requests?view=all&q=holiday', $this->harness->definition(SearchRequestHarness::options(['see_all_parameters' => ['view' => 'all']]))->seeAllUrl($this->query('holiday')));
        self::assertNull($this->harness->definition(SearchRequestHarness::options(['see_all_route' => null]))->seeAllUrl($this->query('holiday')));
    }

    #[Test]
    public function recentsOnlyResolveThisGroupsRouteAndIdShape(): void
    {
        $definition = $this->harness->definition();

        self::assertSame('12', $definition->referenceId(new SearchReference('/requests/12', 'workflow_requests', SearchRequestHarness::ROUTE, ['id' => '12'])));
        self::assertNull($definition->referenceId(new SearchReference('/x/12', 'workflow_requests', 'other_route', ['id' => '12'])));
        self::assertNull($definition->referenceId(new SearchReference('/requests/12', 'workflow_requests', SearchRequestHarness::ROUTE, ['id' => 12])));
        self::assertNull($definition->referenceId(new SearchReference('/requests/12', 'workflow_requests', SearchRequestHarness::ROUTE, ['id' => '1 OR 1'])));
        self::assertNull($definition->referenceId(new SearchReference('/requests', 'workflow_requests', SearchRequestHarness::ROUTE, [])));
    }

    #[Test]
    public function withoutATenantFieldTheAccessEventAloneSeparatesTenants(): void
    {
        $marker     = $this->marker();
        $here       = $this->harness->insert(self::TENANT, 'A ' . $marker);
        $there      = $this->harness->insert(self::TENANT + 1, 'B ' . $marker);
        $definition = $this->harness->definition(SearchRequestHarness::options(['tenant_field' => null, 'archived_field' => null, 'order_field' => null, 'excluded_statuses' => []]));
        $group      = (new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['/requests/' . $there, '/requests/' . $here], array_map(static fn (SearchItem $item): string => $item->url, $group->items));
    }

    #[Test]
    public function anUnmappedFieldFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"missing" is not a mapped field');

        $this->harness->definition(SearchRequestHarness::options(['archived_field' => 'missing']))->baseQuery(self::TENANT, $this->query('a'));
    }

    #[Test]
    public function aClassWithoutAnOrmManagerFailsLoudly(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $definition = new WorkflowRequestSearchDefinition($registry, $this->harness->gate, SearchRequestHarness::urls(), SearchRequestHarness::options());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not managed by a Doctrine ORM entity manager');

        $definition->baseQuery(self::TENANT, $this->query('a'));
    }

    #[Test]
    public function anotherRowTypeFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('row is expected');

        $this->harness->definition()->subjectId(new stdClass());
    }

    #[Test]
    public function aRowWithoutAnIdFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('without a scalar id');

        $this->harness->definition()->subjectId(new SearchRequest(self::TENANT, 'not persisted'));
    }

    #[Test]
    public function arrayRowsAreRefused(): void
    {
        $this->expectException(LogicException::class);

        $this->harness->definition()->toItem(['id' => 1, 'title' => 'x'], $this->query('a'));
    }

    /**
     * @return list<string>
     */
    private function urls(string $text): array
    {
        $group = (new EntitySearchProvider($this->harness->definition(), new EventDispatcher()))->search($this->query($text));

        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->url, $group->items) : [];
    }

    private function query(string $text, string $viewer = self::VIEWER): SearchQuery
    {
        return SearchQuery::forUserIdentifier($viewer, $text, 10);
    }

    private function find(int $id): SearchRequest
    {
        $request = $this->harness->em->find(SearchRequest::class, $id);
        self::assertInstanceOf(SearchRequest::class, $request);

        return $request;
    }

    private function marker(): string
    {
        return 'wfsk' . bin2hex(random_bytes(5));
    }
}
