<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\OwnRequestsNarrower;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchKitAbsentTestCase;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequestHarness;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequestStatus;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function class_alias;
use function class_exists;
use function getenv;
use function is_string;

if (!class_exists(EntitySearchProviderTestCase::class)) {
    class_alias(SearchKitAbsentTestCase::class, EntitySearchProviderTestCase::class);
}

/**
 * Since 1.21.0: SearchKit's provider contract (EntitySearchProviderTestCase), one definition per subject:
 *
 * - "leave": matched on the host-maintained search text, titles from the host's title resolver, a string-backed enum
 *   status column, integer ids and tenant. Budget 3: the search, the access listener and the title resolver
 *   (prepareItems(): one statement, exactly the shown rows).
 * - "purchase": the simple form — the title column is searched and shown, a plain status, string ids and tenant.
 *   Budget 2: the search and the access listener.
 *
 * Visible rows are shared requests of the tenant (no owner); hidden rows belong to another owner — for "leave" the
 * narrower filters them in SQL (and the access event would hide them), for "purchase" only WorkflowRequestAccessCheckEvent
 * hides them; archived rows are archived / soft-deleted (filtered in SQL).
 */
abstract class WorkflowRequestSearchContractTestCase extends EntitySearchProviderTestCase
{
    protected SearchRequestHarness $harness;

    /** The subject under test: "leave" or "purchase". */
    abstract protected static function subject(): string;

    /** The DSN variable of the server, or null for SQLite in memory. */
    abstract protected static function urlVariable(): ?string;

    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.10 is not installed (PHP >= 8.3).');
        }
        $variable = static::urlVariable();
        $url      = $variable === null ? null : getenv($variable);
        if ($variable !== null && (!is_string($url) || $url === '')) {
            self::markTestSkipped($variable . ' is not set (real-database SearchKit contract).');
        }
        $this->harness = new SearchRequestHarness(is_string($url) ? $url : null);
    }

    protected function tearDown(): void
    {
        if (isset($this->harness)) {
            $this->harness->em->getConnection()->close();
        }
    }

    /**
     * The status is matched on its stored value (the enum value / place name) — with the search text, every word.
     */
    public function testStatusMatchesTheStoredValue(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->actAs($tenant, self::VIEWER_A);
        $first = $this->seed($tenant, $marker);
        $other = $this->leave()
            ? $this->harness->insert($tenant, 'Leave ' . $marker, status: SearchRequestStatus::Approved)
            : $this->harness->insertPurchase($tenant, 'Purchase ' . $marker, place: 'ordered');

        self::assertSame([$this->url($first)], $this->urls($marker . ($this->leave() ? ' pending' : ' open')));
        self::assertSame([$this->url($other)], $this->urls($marker . ($this->leave() ? ' APPROVED' : ' ordered')));
        self::assertSame([], $this->urls($marker . ' nostatus'));
    }

    /**
     * A recent's id must have the id column's shape: no "12abc" ever reaches an integer column (PostgreSQL 22P02).
     */
    public function testMalformedRecentIdsResolveToNothing(): void
    {
        [$tenant] = $this->tenants();
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();
        foreach (['12abc', '99999999999999999999', '2147483648', '012', 'a b', ''] as $id) {
            $reference = new SearchReference($this->url($id), $this->definition()->group(), $this->route(), ['id' => $id]);
            self::assertNull($provider->resolve($reference, self::VIEWER_A, null), $id);
        }
        $id = $this->seed($tenant, $this->marker());
        self::assertNotNull($provider->resolve($this->reference($id), self::VIEWER_A, null));
    }

    /**
     * A narrower's long list (2,500 values) is split into IN lists of 1,000 (each its own bound parameter), ORed: on
     * every database the right rows are found, none cut away.
     */
    public function testNarrowerSplitsLongValueLists(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->actAs($tenant, self::VIEWER_A);
        $visible = $this->seed($tenant, $marker);
        $this->seedHidden($tenant, $marker);
        $values = [];
        for ($i = 0; $i < 2498; ++$i) {
            $values[] = 'user' . $i . '@example.com';
        }
        $values[] = self::VIEWER_A;
        $values[] = '';
        $narrower = new OwnRequestsNarrower(static fn (WorkflowRequestSearchCriteria $criteria): WorkflowRequestSearchCriteria => $criteria->whereIn('ownerIdentifier', $values));

        $group = (new EntitySearchProvider($this->harness->definition(static::subject(), narrower: $narrower), new EventDispatcher()))->search($this->query($marker));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame([$this->url($visible)], array_map(static fn (SearchItem $item): string => $item->url, $group->items));
    }

    /**
     * The bound narrower values of one search are capped at 60,000 in all (PostgreSQL allows 65,535 parameters): at
     * the cap the query runs and finds the right rows; one more value, or several fields over it together, fail
     * closed before any SQL with the documented warning.
     */
    public function testNarrowerValuesAreCappedInAll(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->actAs($tenant, self::VIEWER_A);
        $visible = $this->seed($tenant, $marker);
        $this->seedHidden($tenant, $marker);
        $options = $this->leave()
            ? SearchRequestHarness::options('leave', ['narrower_max_values' => 60000])
            : SearchRequestHarness::options('purchase', ['narrower_max_values' => 60000]);
        $owners = static function (int $count): array {
            $values = ['', self::VIEWER_A];
            for ($i = 2; $i < $count; ++$i) {
                $values[] = 'user' . $i . '@example.com';
            }

            return $values;
        };
        $search = function (callable $rule, RecordingLogger $logger) use ($options, $marker): ?SearchGroup {
            $definition = $this->harness->definition(static::subject(), $options, narrower: new OwnRequestsNarrower($rule(...)), logger: $logger);
            $this->harness->counter->reset();

            return (new EntitySearchProvider($definition, new EventDispatcher()))->search($this->query($marker));
        };

        $logger = new RecordingLogger();
        $group  = $search(static fn (WorkflowRequestSearchCriteria $c): WorkflowRequestSearchCriteria => $c->whereIn('ownerIdentifier', $owners(60000)), $logger);
        self::assertInstanceOf(SearchGroup::class, $group, 'at the cap');
        self::assertSame([$this->url($visible)], array_map(static fn (SearchItem $item): string => $item->url, $group->items));
        self::assertSame([], $logger->records);

        foreach ([
            'one over the cap' => static fn (WorkflowRequestSearchCriteria $c): WorkflowRequestSearchCriteria => $c->whereIn('ownerIdentifier', $owners(60001)),
            'several fields'   => static function (WorkflowRequestSearchCriteria $c) use ($owners): void {
                for ($i = 0; $i < 7; ++$i) {
                    $c->whereIn('ownerIdentifier', $owners(10000));
                }
            },
        ] as $case => $rule) {
            $logger = new RecordingLogger();
            self::assertNull($search($rule, $logger), $case);
            self::assertSame(0, $this->harness->counter->count(), $case . ': no SQL');
            self::assertSame(60000, $logger->records[0]['context']['limit'] ?? null, $case . ': the documented warning');
            self::assertGreaterThan(60000, $logger->records[0]['context']['total']);
        }
    }

    protected function definition(): EntitySearchDefinition
    {
        return $this->harness->definition(static::subject());
    }

    protected function queryCounter(): QueryCounter
    {
        return $this->harness->counter;
    }

    protected function tenants(): array
    {
        return $this->leave() ? [7101, 7102] : ['acme', 'globex'];
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->leave()
            ? $this->harness->insert($tenant, 'Leave ' . $text, body: 'body ' . $text, title: 'T ' . $text)
            : $this->harness->insertPurchase($tenant, 'Purchase ' . $text);
    }

    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        return $this->leave()
            ? $this->harness->insert($tenant, 'Leave ' . $text, owner: 'someone-else')
            : $this->harness->insertPurchase($tenant, 'Purchase ' . $text, owner: 'someone-else');
    }

    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        return $this->leave()
            ? $this->harness->insert($tenant, 'Leave ' . $text, archived: true)
            : $this->harness->insertPurchase($tenant, 'Purchase ' . $text, deleted: true);
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference($this->url($id), $this->definition()->group(), $this->route(), ['id' => (string) $id]);
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->harness->gate->tenant  = $tenant;
        $this->harness->gate->viewer  = $userIdentifier;
        $this->harness->gate->enabled = true;
    }

    protected function disableFeature(): void
    {
        $this->harness->gate->enabled = false;
    }

    protected function sensitiveTitles(): bool
    {
        return $this->leave();
    }

    protected function queryBudget(): int
    {
        return $this->leave() ? 3 : 2;
    }

    /**
     * "leave" (sensitive) narrows in SQL with the access listener's own owner rule, so hidden rows never take places
     * in the candidate window. "purchase" (not sensitive, no narrower) is the PHP-only subject: its hidden rows are
     * dropped by the access event alone, so it accepts the window side channel (an inexact total, fewer rows).
     */
    protected function hiddenRowsFilteredInSql(): bool
    {
        return $this->leave();
    }

    protected function prepareItemsBudget(): int
    {
        return 1;
    }

    protected function hasArchivedState(): bool
    {
        return true;
    }

    /** The host's search text (leave) or title (purchase) changes: the next render must show it. */
    protected function changeSeededText(int|string $id, string $text): bool
    {
        $connection = $this->harness->em->getConnection();
        if ($this->leave()) {
            $connection->update('wfsk_search_request', ['search_text' => 'Leave ' . $text, 'title' => 'T ' . $text], ['id' => $id]);
        } else {
            $connection->update('wfsk_purchase_request', ['title' => 'Purchase ' . $text], ['id' => $id]);
        }
        $this->harness->em->clear();

        return true;
    }

    private function leave(): bool
    {
        return static::subject() === 'leave';
    }

    private function route(): string
    {
        return $this->leave() ? SearchRequestHarness::ROUTE : SearchRequestHarness::PURCHASE_ROUTE;
    }

    private function url(int|string $id): string
    {
        return ($this->leave() ? '/requests/' : '/purchases/') . $id;
    }

    /**
     * @return list<string>
     */
    private function urls(string $text): array
    {
        $group = $this->provider()->search($this->query($text));

        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->url, $group->items) : [];
    }
}
