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
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\OwnRequestsNarrower;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\PurchaseRequest;
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
use function range;
use function sort;
use function str_repeat;

/**
 * Since 1.21.0: the workflow request groups beyond SearchKit's contract kit — search text and status matching,
 * excluded statuses, the access event, the title resolver (visible ids only, one batch), the host narrowing, what a
 * result shows (never the body or the approver note), labels, links and recents ids.
 */
final class WorkflowRequestSearchDefinitionTest extends TestCase
{
    private const TENANT = 7201;

    private const VIEWER = 'alice@example.com';

    private SearchRequestHarness $harness;

    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.10 is not installed (PHP >= 8.3).');
        }
        $this->harness               = new SearchRequestHarness();
        $this->harness->gate->tenant = self::TENANT;
        $this->harness->gate->viewer = self::VIEWER;
    }

    #[Test]
    public function matchesTheSearchTextAndTheStatusOnly(): void
    {
        $marker   = $this->marker();
        $pending  = $this->harness->insert(self::TENANT, 'Ana ' . $marker, body: 'secret-body ' . $marker, title: 'Holiday');
        $approved = $this->harness->insert(self::TENANT, 'Bea ' . $marker, status: SearchRequestStatus::Approved);
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
        self::assertSame([], $this->urls('Holiday'), 'with a search text, the title column is not searched');
        self::assertSame([], $this->urls($marker . ' draft'), 'excluded statuses are filtered in SQL');
        self::assertSame([self::TENANT, self::VIEWER], $this->harness->gate->enabledCalls[0]);
    }

    #[Test]
    public function titlesComeFromTheResolverInOneBatchOfVisibleIdsOnly(): void
    {
        $marker  = $this->marker();
        $shown   = $this->harness->insert(self::TENANT, 'A ' . $marker, title: 'Ana — Holiday');
        $hidden  = $this->harness->insert(self::TENANT, 'B ' . $marker, owner: 'bob@example.com', title: 'Bob — Sick leave');
        $denied  = $this->harness->insert(self::TENANT, 'C ' . $marker, title: 'Cid — Holiday');
        $untitle = $this->harness->insert(self::TENANT, 'D ' . $marker, title: null);
        $this->harness->insert(self::TENANT + 1, 'E ' . $marker, title: 'Other tenant');
        $this->harness->dispatcher->addListener(WorkflowRequestAccessCheckEvent::class, static function (WorkflowRequestAccessCheckEvent $event) use ($denied): void {
            $event->deny((string) $denied);
        }, -10);

        $group = $this->search($marker);
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['Leave: Ana — Holiday', 'Request'], array_map(static fn (SearchItem $item): string => $item->title, $group->items));
        self::assertCount(1, $this->harness->titles->calls, 'one batch per search');
        $call = $this->harness->titles->calls[0];
        $ids  = $call['ids'];
        sort($ids);
        self::assertSame([(string) $shown, (string) $untitle], $ids, 'never a hidden, denied or other-tenant id');
        self::assertSame(['leave', self::VIEWER, self::TENANT], [$call['subject'], $call['user'], $call['tenant']]);
        self::assertNotContains((string) $hidden, $call['ids']);
    }

    #[Test]
    public function aResolverThatLeavesAnIdOutFailsClosed(): void
    {
        $id         = $this->harness->insert(self::TENANT, 'raw column text', title: null);
        $definition = $this->harness->definition(options: SearchRequestHarness::options('leave', ['title_field' => 'searchText']));
        $query      = $this->query('x');
        $context    = new SearchItemContext();
        self::assertTrue($definition->featureEnabled(self::TENANT, $query));

        $definition->prepareItems([$this->find($id), $this->find($id)], $query, $context);
        self::assertSame([(string) $id], $this->harness->titles->calls[0]['ids'], 'one id once');
        self::assertSame('Request', $definition->toPreparedItem($this->find($id), $query, $context)->title, 'never the title_field column');
        self::assertSame('Request', $definition->toPreparedItem($this->find($id), $query, new SearchItemContext())->title, 'nothing prepared');
        self::assertCount(1, $this->harness->titles->calls, 'toPreparedItem() never calls the resolver');

        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['search.untitled_request' => 'Solicitud'], 'es', WorkflowRequestSearchDefinition::TRANSLATION_DOMAIN);
        $spanish = $this->harness->definition(translator: $translator);
        self::assertSame('Solicitud', $spanish->toPreparedItem($this->find($id), new SearchQuery('x', null, 10, userIdentifier: self::VIEWER, locale: 'es'), new SearchItemContext())->title);
    }

    #[Test]
    public function withoutAResolverAnEmptyTitleColumnShowsTheGenericTitle(): void
    {
        $this->harness->gate->tenant = 'acme';
        $this->harness->insertPurchase('acme', '');
        $definition = $this->harness->definition('purchase');
        $row        = $this->harness->em->getRepository(PurchaseRequest::class)->findOneBy(['title' => '']);
        self::assertInstanceOf(PurchaseRequest::class, $row);

        self::assertSame('Request', $definition->toPreparedItem($row, $this->query('x'), new SearchItemContext())->title);
    }

    #[Test]
    public function noDecidedSearchOrNoRowsNoTitleLookup(): void
    {
        $id         = $this->harness->insert(self::TENANT, 'x');
        $context    = new SearchItemContext();
        $definition = $this->harness->definition();
        $query      = $this->query('x');

        $definition->prepareItems([$this->find($id)], $query, $context);
        self::assertSame('Request', $definition->toPreparedItem($this->find($id), $query, $context)->title, 'no search decided a tenant: generic title');
        self::assertTrue($definition->featureEnabled(self::TENANT, $query));
        $definition->prepareItems([], $query, $context);
        $this->harness->definition('purchase')->prepareItems([$this->find($id)], $query, $context);

        self::assertSame([], $this->harness->titles->calls);
        self::assertFalse($context->has('workflow_request_titles'));
    }

    #[Test]
    public function titlesUseTheTenantTheSearchDecidedNotAGateAskedAgain(): void
    {
        $id         = $this->harness->insert(self::TENANT, 'x');
        $definition = $this->harness->definition();
        $query      = $this->query('x');
        self::assertTrue($definition->featureEnabled(self::TENANT, $query));
        $definition->baseQuery(self::TENANT, $query);

        $this->harness->gate->tenant = self::TENANT + 1; // the gate changes its answer mid-search
        $this->harness->gate->viewer = 'someone-else';
        $definition->prepareItems([$this->find($id)], $query, new SearchItemContext());

        self::assertSame([['leave', self::TENANT]], array_map(static fn (array $call): array => [$call['subject'], $call['tenant']], $this->harness->titles->calls));
    }

    #[Test]
    public function theResolverOnlyGetsTheShownPage(): void
    {
        $marker = $this->marker();
        $first  = $this->harness->insert(self::TENANT, 'A ' . $marker);
        $this->harness->insert(self::TENANT, 'B ' . $marker);

        $group = (new EntitySearchProvider($this->harness->definition(), new EventDispatcher()))->search(SearchQuery::forUserIdentifier(self::VIEWER, $marker, 1));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        self::assertSame([[(string) $first]], array_map(static fn (array $call): array => $call['ids'], $this->harness->titles->calls));
    }

    #[Test]
    public function oneAccessEventCarriesTheSubjectIdsAndStatusesOnly(): void
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
        self::assertSame('leave', $events[0]->subject);
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

        $definition = new WorkflowRequestSearchDefinition(new TestManagerRegistry($this->harness->em), $this->harness->gate, SearchRequestHarness::urls(), SearchRequestHarness::options(), new EventDispatcher());
        self::assertNull((new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker)), 'no listener: deny by default');
    }

    #[Test]
    public function aPurchaseIsSearchedAndShownByItsTitleWithAPlainStatus(): void
    {
        $this->harness->gate->tenant = 'acme';
        $marker                      = $this->marker();
        $open                        = $this->harness->insertPurchase('acme', 'Laptop ' . $marker);
        $ordered                     = $this->harness->insertPurchase('acme', 'Desk ' . $marker, place: 'ordered');
        $this->harness->insertPurchase('acme', 'Chair ' . $marker, deleted: true);
        $this->harness->insertPurchase('globex', 'Other ' . $marker);
        $definition = $this->harness->definition('purchase');

        $group = (new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame('workflow_purchase', $group->key);
        $items = array_map(static fn (SearchItem $i): array => [$i->url, $i->title, $i->subtitle], $group->items);
        sort($items);
        $expected = [['/purchases/' . $open, 'Laptop ' . $marker, 'open'], ['/purchases/' . $ordered, 'Desk ' . $marker, 'ordered']];
        sort($expected);
        self::assertSame($expected, $items, 'same created_at: any order');
        self::assertSame(['purchase'], $this->harness->listener->subjects);
        self::assertSame([], $this->harness->titles->calls, 'no resolver for this subject');
    }

    #[Test]
    public function anItemShowsTheTitleTheStatusAndTheRouteOnly(): void
    {
        $id         = $this->harness->insert(self::TENANT, 'x', body: 'secret', title: str_repeat('x', 250));
        $definition = $this->harness->definition(options: SearchRequestHarness::options('leave', ['route_parameters' => ['tab' => 'details']]));
        $query      = $this->query('x');
        $context    = new SearchItemContext();
        self::assertTrue($definition->featureEnabled(self::TENANT, $query));
        $definition->prepareItems([$this->find($id)], $query, $context);

        $item = $definition->toPreparedItem($this->find($id), $query, $context);
        self::assertSame(WorkflowRequestSearchDefinition::MAX_TITLE_LENGTH, mb_strlen($item->title));
        self::assertSame('/requests/' . $id . '?tab=details', $item->url);
        self::assertSame('pending', $item->subtitle);
        self::assertSame('Requests', $definition->label($query));
        self::assertSame((string) $id, $definition->subjectId($this->find($id)));
    }

    #[Test]
    public function labelsAreTranslatedPerSubject(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['search.group.workflow_requests' => 'Solicitudes'], 'es', WorkflowRequestSearchDefinition::TRANSLATION_DOMAIN);
        $translator->addResource('array', ['search.purchases' => 'Compras'], 'es', 'app');
        $translator->addResource('array', ['leave.status.pending' => 'Pendiente'], 'es', 'leave');
        $id    = $this->harness->insert(self::TENANT, 'A');
        $query = new SearchQuery('a', null, 10, userIdentifier: self::VIEWER, locale: 'es');

        $translated = $this->harness->definition(options: SearchRequestHarness::options('leave', ['status_label_domain' => 'leave', 'status_label_prefix' => 'leave.status.']), translator: $translator);
        self::assertSame('Solicitudes', $translated->label($query));
        self::assertSame('Pendiente', $translated->toPreparedItem($this->find($id), $query, new SearchItemContext())->subtitle);
        self::assertSame('Compras', $this->harness->definition('purchase', translator: $translator)->label($query));

        $noTranslator = $this->harness->definition(options: SearchRequestHarness::options('leave', ['status_label_domain' => 'leave']));
        self::assertSame('pending', $noTranslator->toPreparedItem($this->find($id), $query, new SearchItemContext())->subtitle);
    }

    #[Test]
    public function hooksFollowTheOptions(): void
    {
        $definition = $this->harness->definition(options: SearchRequestHarness::options('leave', ['icon' => 'tabler:beach', 'min_length' => 2, 'sensitive' => false]));

        self::assertSame('workflow_leave_requests', $definition->group());
        self::assertSame('tabler:beach', $definition->icon());
        self::assertSame('workflow_leave', $definition->subjectType());
        self::assertSame('workflow_purchase', $this->harness->definition('purchase')->subjectType());
        self::assertSame(['r.searchText', 'r.status'], $definition->searchableFields());
        self::assertSame(['r.title', 'r.place'], $this->harness->definition('purchase')->searchableFields());
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
        self::assertSame('/requests?view=all&q=holiday', $this->harness->definition(options: SearchRequestHarness::options('leave', ['see_all_parameters' => ['view' => 'all']]))->seeAllUrl($this->query('holiday')));
        self::assertNull($this->harness->definition('purchase')->seeAllUrl($this->query('holiday')));
    }

    #[Test]
    public function recentIdsFollowTheIdColumnType(): void
    {
        $leave    = $this->harness->definition();
        $purchase = $this->harness->definition('purchase');
        $ref      = static fn (string $route, mixed $id): SearchReference => new SearchReference('/x', 'k', $route, ['id' => $id]);

        foreach (['12' => '12', '0' => '0', '2147483647' => '2147483647', '2147483648' => null, '9223372036854775807' => null, '12abc' => null, '012' => null, '99999999999999999999' => null, '' => null, '-1' => null] as $id => $expected) {
            self::assertSame($expected, $leave->referenceId($ref(SearchRequestHarness::ROUTE, (string) $id)), 'integer id ' . $id);
        }
        self::assertNull($leave->referenceId($ref(SearchRequestHarness::ROUTE, 12)), 'not a string');
        self::assertNull($leave->referenceId($ref('other_route', '12')));
        self::assertNull($leave->referenceId(new SearchReference('/x', 'k', SearchRequestHarness::ROUTE, [])));
        self::assertSame('p12ab', $purchase->referenceId($ref(SearchRequestHarness::PURCHASE_ROUTE, 'p12ab')));
        self::assertNull($purchase->referenceId($ref(SearchRequestHarness::PURCHASE_ROUTE, 'p 1')));
    }

    #[Test]
    public function theNarrowerKeepsTheViewersOwnRequestsInTheWindow(): void
    {
        $marker = $this->marker();
        $mine   = $this->harness->insert(self::TENANT, 'Mine ' . $marker, owner: self::VIEWER);
        for ($i = 0; $i < 25; ++$i) {
            $this->harness->insert(self::TENANT, 'Theirs ' . $marker, owner: 'bob@example.com');
        }
        $newestFirst = SearchRequestHarness::options('leave', ['order_field' => null, 'narrower' => null, 'accept_window_side_channel' => true]);
        self::assertNull((new EntitySearchProvider($this->harness->definition(options: $newestFirst), new EventDispatcher()))->search($this->query($marker)), 'newest first: 20 hidden rows fill the window (starvation)');

        $narrower = new OwnRequestsNarrower(static function (WorkflowRequestSearchCriteria $criteria, string $viewer): void {
            $criteria->whereAny(['ownerIdentifier' => [$viewer, ''], 'tenantId' => -1])->whereIn('status', ['pending', 'approved']);
        });
        $group = (new EntitySearchProvider($this->harness->definition(options: $newestFirst, narrower: $narrower), new EventDispatcher()))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['/requests/' . $mine], array_map(static fn (SearchItem $item): string => $item->url, $group->items));
        self::assertSame([['leave', self::VIEWER, self::TENANT]], $narrower->calls);
    }

    #[Test]
    public function noneLeavesNoGroupBeforeAnySql(): void
    {
        $marker = $this->marker();
        $this->harness->insert(self::TENANT, 'A ' . $marker);
        $none       = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->none());
        $definition = $this->harness->definition(narrower: $none);

        $this->harness->counter->reset();
        self::assertNull((new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker)));
        self::assertSame(0, $this->harness->counter->count(), 'no SQL at all');
        self::assertSame([], $this->harness->titles->calls);
        self::assertCount(1, $none->calls, 'asked once per search');
        self::assertStringContainsString('1 = 0', $definition->baseQuery(self::TENANT, $this->query($marker))->getDQL(), 'a direct baseQuery() finds nothing either');
    }

    #[Test]
    public function theNarrowerIsAskedOncePerQueryAndTenant(): void
    {
        $narrower   = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('ownerIdentifier', ['']));
        $definition = $this->harness->definition(narrower: $narrower);
        $query      = $this->query('x');

        self::assertTrue($definition->featureEnabled(self::TENANT, $query));
        $definition->baseQuery(self::TENANT, $query);
        self::assertCount(1, $narrower->calls);
        $definition->baseQuery(self::TENANT + 1, $query);
        self::assertSame([self::TENANT, self::TENANT + 1], array_map(static fn (array $call): int|string => $call[2], $narrower->calls), 'another tenant asks again');
    }

    #[Test]
    public function sqliteHasTheLowerTotalLimit(): void
    {
        $values     = array_map('strval', range(1, WorkflowRequestSearchCriteria::SQLITE_MAX_TOTAL_VALUES + 1));
        $logger     = new RecordingLogger();
        $narrower   = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('ownerIdentifier', $values));
        $definition = $this->harness->definition(options: SearchRequestHarness::options('leave', ['narrower_max_values' => 60000]), narrower: $narrower, logger: $logger);

        self::assertFalse($definition->featureEnabled(self::TENANT, $this->query('x')), '32001 values on SQLite (32766 variables)');
        self::assertSame(WorkflowRequestSearchCriteria::SQLITE_MAX_TOTAL_VALUES, $logger->records[0]['context']['limit']);
    }

    #[Test]
    public function anEmptyListMatchesNothing(): void
    {
        $marker = $this->marker();
        $this->harness->insert(self::TENANT, 'A ' . $marker);
        $empty = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('ownerIdentifier', []));

        self::assertNull((new EntitySearchProvider($this->harness->definition(narrower: $empty), new EventDispatcher()))->search($this->query($marker)));
    }

    #[Test]
    public function tooManyValuesFailClosedWithAWarning(): void
    {
        $marker = $this->marker();
        $this->harness->insert(self::TENANT, 'A ' . $marker);
        $logger     = new RecordingLogger();
        $narrower   = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('ownerIdentifier', ['', ...array_map('strval', range(1, 1000))]));
        $definition = new WorkflowRequestSearchDefinition(new TestManagerRegistry($this->harness->em), $this->harness->gate, SearchRequestHarness::urls(), SearchRequestHarness::options('leave', ['narrower_max_values' => 1000]), $this->harness->dispatcher, null, $this->harness->titles, $narrower, $logger);

        $this->harness->counter->reset();
        self::assertNull((new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker)));
        self::assertSame(0, $this->harness->counter->count());
        self::assertStringContainsString('gave too many values', $logger->records[0]['message'] ?? '');
        $context = $logger->records[0]['context'];
        self::assertSame(['leave', 1001, 60000, 1000, 'ownerIdentifier'], [$context['subject'], $context['total'], $context['limit'], $context['max'], $context['fields']]);
        self::assertStringContainsString('1 = 0', $definition->baseQuery(self::TENANT, $this->query($marker))->getDQL(), 'a direct baseQuery() finds nothing either');
    }

    #[Test]
    public function theNarrowerOnlyNamesMappedFields(): void
    {
        $narrower = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('requester', [1]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"requester" is not a mapped field');

        $this->harness->definition(narrower: $narrower)->baseQuery(self::TENANT, $this->query('a'));
    }

    #[Test]
    public function anUnmappedFieldFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('subjects.leave: "missing" is not a mapped field');

        $this->harness->definition(options: SearchRequestHarness::options('leave', ['archived_field' => 'missing']))->baseQuery(self::TENANT, $this->query('a'));
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

        $this->harness->definition('purchase')->toPreparedItem(['id' => 1, 'title' => 'x'], $this->query('a'), new SearchItemContext());
    }

    #[Test]
    public function aPurchaseRowIsNotALeaveRow(): void
    {
        $this->expectException(LogicException::class);

        $this->harness->definition()->subjectId(new PurchaseRequest('p1', 'acme', 'x'));
    }

    private function search(string $text): ?SearchGroup
    {
        return (new EntitySearchProvider($this->harness->definition(), new EventDispatcher()))->search($this->query($text));
    }

    /**
     * @return list<string>
     */
    private function urls(string $text): array
    {
        $group = $this->search($text);

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
