<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Bridge\SearchKit;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchGateInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRequestTitleResolverInterface;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use WeakMap;

use function array_chunk;
use function count;
use function ctype_digit;
use function implode;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function ltrim;
use function mb_substr;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Since 1.21.0: one SearchKit group (SearchKit >= 1.10, {@see EntitySearchDefinition}) per subject of
 * `nowo_workflow.search_kit.subjects` — the host's workflow requests of one type (e.g. leave requests), matched on a
 * viewer-neutral search text and the status, only those the viewer may see.
 *
 * - Tenant and feature switch: the subject's {@see WorkflowRequestSearchGateInterface}, asked on every search.
 * - SQL first ({@see baseQuery()}): the tenant field, archived rows, excluded statuses and the optional host narrowing
 *   ({@see WorkflowRequestSearchNarrowerInterface}) are conditions of the query; the newest first.
 * - Then `view`: one {@see WorkflowRequestAccessCheckEvent} for all candidates ({@see filterVisible()}), **deny by
 *   default**, so the host's access rules decide (SearchKit's own host event runs after it).
 * - Titles: the subject's {@see WorkflowRequestTitleResolverInterface} in one batch for exactly the shown rows
 *   ({@see prepareItems()}), else `title_field`. A result shows the title and the status label only.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the entity manager, the tenant and the viewer are resolved on
 * every call. The titles of a render live in SearchKit's per-render SearchItemContext, never in a property.
 */
final class WorkflowRequestSearchDefinition extends EntitySearchDefinition
{
    /** Query alias of the request entity. */
    public const ALIAS = 'r';

    /** The bundle's translation domain (the default group label). */
    public const TRANSLATION_DOMAIN = 'NowoWorkflowBundle';

    /** The longest title shown (characters). */
    public const MAX_TITLE_LENGTH = 200;

    /** The shape of a string request id in a stored recent (client input). */
    private const REFERENCE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    /** Doctrine integer id types and their largest value: a recent's id must then be digits within that range. */
    private const INTEGER_MAX = [Types::SMALLINT => '32767', Types::INTEGER => '2147483647', Types::BIGINT => '9223372036854775807'];

    /** Translation key of the generic title of a request without one. */
    public const UNTITLED = 'search.untitled_request';

    /** @var WeakMap<SearchQuery, array{tenant: int|string, criteria: ?WorkflowRequestSearchCriteria}> one search's decided tenant and narrowing, keyed by its query */
    private readonly WeakMap $searches;

    /** Key of the resolved titles in the SearchItemContext of a render. */
    private const CONTEXT_TITLES = 'workflow_request_titles';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly WorkflowRequestSearchGateInterface $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly WorkflowRequestSearchOptions $options,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?WorkflowRequestTitleResolverInterface $titleResolver = null,
        private readonly ?WorkflowRequestSearchNarrowerInterface $narrower = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->searches = new WeakMap();
    }

    public function group(): string
    {
        return $this->options->groupKey();
    }

    public function label(SearchQuery $query): string
    {
        return $this->translator?->trans($this->options->label, [], $this->options->labelDomain ?? self::TRANSLATION_DOMAIN, $query->locale) ?? 'Requests';
    }

    public function icon(): ?string
    {
        return $this->options->icon;
    }

    /** `workflow_<subject>`: a host ITEM_VISIBILITY listener tells the subjects apart. */
    public function subjectType(): string
    {
        return $this->options->subjectType();
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->gate->tenantOf($query->userIdentifier);
    }

    /**
     * The gate's switch, then the narrowing: a narrower that gives {@see WorkflowRequestSearchCriteria::none()} — or
     * more values for a field than `narrower_max_values`, or more bound values in all than the platform allows
     * ({@see totalLimit()}; logged) — leaves no group, before any SQL runs. The decided tenant is recorded for the
     * search (its query), so prepareItems() resolves titles for that tenant.
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        if (!$this->gate->isEnabled($tenant, $query->userIdentifier)) {
            return false;
        }
        $criteria = $this->narrowing($tenant, $query);
        if (!$criteria instanceof WorkflowRequestSearchCriteria) {
            return true;
        }
        if ($this->exceedsLimits($criteria)) {
            $this->logger?->warning('Workflow request search: the narrower of subject "{subject}" gave too many values ({total} in all, limit {limit}; more than {max} for: {fields}); no results (fail closed).', [
                'bundle'  => 'NowoWorkflowBundle',
                'subject' => $this->options->subject,
                'total'   => $criteria->totalValues(),
                'limit'   => $this->totalLimit($criteria),
                'max'     => $criteria->maxValues,
                'fields'  => implode(', ', $criteria->overflowingFields()),
            ]);

            return false;
        }

        return !$criteria->isNone();
    }

    public function searchableFields(): array
    {
        return [$this->field($this->options->searchField()), $this->field($this->options->statusField)];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        $options  = $this->options;
        $metadata = $this->metadata();
        /** @var class-string $class the configured request entity (its metadata was just read) */
        $class = $options->subjectClass;
        $order = $options->orderField ?? $options->idField;
        foreach ([$options->idField, $options->searchTextField, $options->titleField, $options->statusField, $options->tenantField, $options->archivedField, $order] as $field) {
            $this->assertField($metadata, $field);
        }
        $qb = $this->entityManager()->createQueryBuilder()
            ->select(self::ALIAS)
            ->from($class, self::ALIAS)
            ->orderBy($this->field($order), 'DESC')
            ->addOrderBy($this->field($options->idField), 'ASC');
        if ($options->tenantField !== null) {
            $qb->andWhere(sprintf('%s = :workflow_request_tenant', $this->field($options->tenantField)))->setParameter('workflow_request_tenant', $tenant);
        }
        if ($options->archivedField !== null) {
            $qb->andWhere(sprintf('%s IS NULL', $this->field($options->archivedField)));
        }
        if ($options->excludedStatuses !== []) {
            $qb->andWhere(sprintf('%s NOT IN (:workflow_request_excluded)', $this->field($options->statusField)))->setParameter('workflow_request_excluded', $options->excludedStatuses);
        }
        $criteria = $this->narrowing($tenant, $query);
        if ($criteria instanceof WorkflowRequestSearchCriteria) {
            $this->narrow($criteria, $qb, $metadata);
        }

        return $qb;
    }

    /**
     * One {@see WorkflowRequestAccessCheckEvent} for every candidate: only the requests a listener allowed (and none
     * denied) stay. Without an event dispatcher nothing is visible.
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        if (!$this->dispatcher instanceof EventDispatcherInterface || $rows === []) {
            return [];
        }
        $refs = [];
        foreach ($rows as $row) {
            $refs[] = new WorkflowRequestRef($this->requestId($row), $this->status($row));
        }
        $event = new WorkflowRequestAccessCheckEvent($this->options->subject, $this->options->subjectClass, $query->userIdentifier, $tenant, $refs);
        $this->dispatcher->dispatch($event);

        $visible = [];
        foreach ($rows as $index => $row) {
            if ($event->isVisible($refs[$index]->id)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * The titles of the shown rows in one {@see WorkflowRequestTitleResolverInterface} call (nothing without a resolver),
     * kept in this render's context. SearchKit calls it once per page with exactly the shown rows (after the access
     * event, SearchKit's host event and the page cut) and once per resolved recent: never with a hidden row.
     *
     * The tenant is the one this search decided (featureEnabled() / baseQuery() of the same query), never asked again:
     * without one (no such search) the resolver is not called and the rows show the generic title (fail closed).
     *
     * @param list<array<string, mixed>|object> $shownRows
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        $search = $this->searches[$query] ?? null;
        if (!$this->titleResolver instanceof WorkflowRequestTitleResolverInterface || $shownRows === [] || $search === null) {
            return;
        }
        $tenant = $search['tenant'];
        $ids    = [];
        $seen   = [];
        foreach ($shownRows as $row) {
            $id = $this->requestId($row);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $ids[]     = $id;
            }
        }
        $context->set(self::CONTEXT_TITLES, $this->titleResolver->titles($this->options->subject, $ids, $query->userIdentifier, $tenant, $query->locale));
    }

    public function subjectId(array|object $row): string
    {
        return $this->requestId($row);
    }

    /**
     * The title: with a title resolver, only what it gave for this render (fail closed: an id it left out shows the
     * generic title, never the `title_field` column); without one, `title_field`. The status label; the route.
     */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $id = $this->requestId($row);
        if ($this->titleResolver instanceof WorkflowRequestTitleResolverInterface) {
            $titles = $context->get(self::CONTEXT_TITLES, []);
            $title  = is_array($titles) ? ($titles[$id] ?? null) : null;
        } else {
            $title = $this->value($row, (string) $this->options->titleField);
        }
        $title = is_string($title) || is_int($title) ? mb_substr((string) $title, 0, self::MAX_TITLE_LENGTH) : '';

        return new SearchItem(
            $title !== '' ? $title : ($this->translator?->trans(self::UNTITLED, [], self::TRANSLATION_DOMAIN, $query->locale) ?? 'Request'),
            $this->urls->generate($this->options->route, [$this->options->routeIdParameter => $id] + $this->options->routeParameters),
            $this->statusLabel($this->status($row), $query),
        );
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return $this->options->seeAllRoute === null ? null : SeeAllLink::build($this->urls, $this->options->seeAllRoute, $this->options->seeAllParameters, $query);
    }

    public function minLength(): int
    {
        return $this->options->minLength;
    }

    public function maxCandidates(): int
    {
        return $this->options->candidates();
    }

    public function sensitive(): bool
    {
        return $this->options->sensitive;
    }

    public function idField(): string
    {
        return $this->field($this->options->idField);
    }

    /**
     * A stored recent is client input: it must be this subject's route, with an id of the id field's shape (digits
     * within the column type's range for a smallint / integer / bigint column, so the database never sees "12abc" or
     * an out-of-range number; else letters, digits, `_`, `-`).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters[$this->options->routeIdParameter] ?? null;
        if ($reference->route !== $this->options->route || !is_string($id)) {
            return null;
        }
        $type = $this->metadata()->getTypeOfField($this->options->idField);
        if (is_string($type) && isset(self::INTEGER_MAX[$type])) {
            return $this->isIntegerId($id, self::INTEGER_MAX[$type]) ? $id : null;
        }

        return preg_match(self::REFERENCE_ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** Digits only, no leading zero, at most the column type's largest value. */
    private function isIntegerId(string $id, string $max): bool
    {
        if (!ctype_digit($id) || ($id !== '0' && ltrim($id, '0') !== $id)) {
            return false;
        }

        return strlen($id) < strlen($max) || (strlen($id) === strlen($max) && $id <= $max);
    }

    /**
     * Records the tenant this search decided and returns its narrowing (null without a narrower): asked once per query
     * object and tenant, and kept with the query (a WeakMap keyed by it: featureEnabled(), baseQuery() and
     * prepareItems() of one search share it and it goes with the query; another tenant asks again).
     */
    private function narrowing(int|string $tenant, SearchQuery $query): ?WorkflowRequestSearchCriteria
    {
        $search = $this->searches[$query] ?? null;
        if ($search !== null && $search['tenant'] === $tenant) {
            return $search['criteria'];
        }
        $criteria = null;
        if ($this->narrower instanceof WorkflowRequestSearchNarrowerInterface) {
            $criteria = new WorkflowRequestSearchCriteria($this->options->narrowerMaxValues);
            $this->narrower->narrow($this->options->subject, $criteria, $query->userIdentifier, $tenant);
        }
        // @igor-ignore - keyed by the search's own query object: the entry goes with that query (WeakMap)
        $this->searches[$query] = ['tenant' => $tenant, 'criteria' => $criteria];

        return $criteria;
    }

    /** Whether the narrowing breaks a per-field limit or the platform's total limit. */
    private function exceedsLimits(WorkflowRequestSearchCriteria $criteria): bool
    {
        return $criteria->overflowingFields() !== [] || $criteria->totalValues() > $this->totalLimit($criteria);
    }

    /**
     * The most bound narrower values of one query on this connection: {@see WorkflowRequestSearchCriteria::SQLITE_MAX_TOTAL_VALUES}
     * on SQLite (32766 variables), else {@see WorkflowRequestSearchCriteria::MAX_TOTAL_VALUES} (PostgreSQL: 65535). The
     * platform is only read when the total could exceed the lower limit.
     */
    private function totalLimit(WorkflowRequestSearchCriteria $criteria): int
    {
        if ($criteria->totalValues() <= WorkflowRequestSearchCriteria::SQLITE_MAX_TOTAL_VALUES) {
            return WorkflowRequestSearchCriteria::MAX_TOTAL_VALUES;
        }

        return $this->entityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform
            ? WorkflowRequestSearchCriteria::SQLITE_MAX_TOTAL_VALUES
            : WorkflowRequestSearchCriteria::MAX_TOTAL_VALUES;
    }

    /**
     * The host narrowing: every condition (fields ORed) ANDed to the query; a field's values in IN lists of at most
     * {@see WorkflowRequestSearchCriteria::CHUNK}, ORed, each its own bound parameter. none() or an overflowing field
     * (featureEnabled() already refused them) leave nothing.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function narrow(WorkflowRequestSearchCriteria $criteria, QueryBuilder $qb, ClassMetadata $metadata): void
    {
        if ($criteria->isNone() || $this->exceedsLimits($criteria)) {
            $qb->andWhere('1 = 0');

            return;
        }
        foreach ($criteria->conditions() as $index => $condition) {
            $terms = [];
            foreach ($condition as $field => $values) {
                $this->assertField($metadata, $field);
                if ($values === []) {
                    $terms[] = '1 = 0';

                    continue;
                }
                foreach (array_chunk($values, WorkflowRequestSearchCriteria::CHUNK) as $chunk) {
                    $parameter = sprintf('workflow_request_narrow_%d_%d', $index, count($terms));
                    $terms[]   = sprintf('%s IN (:%s)', $this->field($field), $parameter);
                    $qb->setParameter($parameter, $chunk);
                }
            }
            $qb->andWhere('(' . implode(' OR ', $terms) . ')');
        }
    }

    /** @param ClassMetadata<object> $metadata */
    private function assertField(ClassMetadata $metadata, ?string $field): void
    {
        if ($field !== null && !$metadata->hasField($field)) {
            throw new LogicException(sprintf('nowo_workflow.search_kit.subjects.%s: "%s" is not a mapped field of %s.', $this->options->subject, $field, $this->options->subjectClass));
        }
    }

    /** The label of a status: its translation when `status_label_domain` is set, else the stored value. */
    private function statusLabel(string $status, SearchQuery $query): string
    {
        $domain = $this->options->statusLabelDomain;
        if ($domain === null || $status === '' || !$this->translator instanceof TranslatorInterface) {
            return $status;
        }

        return $this->translator->trans($this->options->statusLabelPrefix . $status, [], $domain, $query->locale);
    }

    /** @param array<string, mixed>|object $row */
    private function requestId(array|object $row): string
    {
        $id = $this->value($row, $this->options->idField);
        if (!is_string($id) && !is_int($id) && !$id instanceof Stringable) {
            throw new LogicException(sprintf('A %s row without a scalar id.', $this->options->subjectClass));
        }

        return (string) $id;
    }

    /**
     * The stored status: a place name, or the value of a string-backed enum field (Doctrine gives the backing value).
     *
     * @param array<string, mixed>|object $row
     */
    private function status(array|object $row): string
    {
        $status = $this->value($row, $this->options->statusField);

        return is_string($status) || is_int($status) ? (string) $status : '';
    }

    /** @param array<string, mixed>|object $row */
    private function value(array|object $row, string $field): mixed
    {
        if (!is_object($row) || !$row instanceof $this->options->subjectClass) {
            throw new LogicException(sprintf('A %s row is expected.', $this->options->subjectClass));
        }

        return $this->metadata()->getFieldValue($row, $field);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var class-string $class */
        $class   = $this->options->subjectClass;
        $manager = $this->doctrine->getManagerForClass($class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('nowo_workflow.search_kit.subjects.%s: %s is not managed by a Doctrine ORM entity manager.', $this->options->subject, $this->options->subjectClass));
        }

        return $manager;
    }

    /** @return ClassMetadata<object> */
    private function metadata(): ClassMetadata
    {
        return $this->entityManager()->getClassMetadata($this->options->subjectClass);
    }

    private function field(string $field): string
    {
        return self::ALIAS . '.' . $field;
    }
}
