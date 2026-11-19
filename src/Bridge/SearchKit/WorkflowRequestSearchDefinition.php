<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Bridge\SearchKit;

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
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchGateInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowRequestTitleResolverInterface;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stringable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use WeakMap;

use function count;
use function ctype_digit;
use function implode;
use function in_array;
use function is_int;
use function is_object;
use function is_string;
use function ltrim;
use function mb_substr;
use function preg_match;
use function sprintf;
use function strlen;

use const PHP_INT_MAX;

/**
 * Since 1.21.0: one SearchKit group (SearchKit >= 1.9, {@see EntitySearchDefinition}) per subject of
 * `nowo_workflow.search_kit.subjects` — the host's workflow requests of one type (e.g. leave requests), matched on a
 * viewer-neutral search text and the status, only those the viewer may see.
 *
 * - Tenant and feature switch: the subject's {@see WorkflowRequestSearchGateInterface}, asked on every search.
 * - SQL first ({@see baseQuery()}): the tenant field, archived rows, excluded statuses and the optional host narrowing
 *   ({@see WorkflowRequestSearchNarrowerInterface}) are conditions of the query; the newest first.
 * - Then `view`: one {@see WorkflowRequestAccessCheckEvent} for all candidates ({@see filterVisible()}), **deny by
 *   default**, so the host's access rules decide (SearchKit's own host event runs after it).
 * - Titles: the subject's {@see WorkflowRequestTitleResolverInterface} in one batch, for visible ids only
 *   ({@see prepareItems()}), else `title_field`. A result shows the title and the status label only.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the entity manager, the tenant and the viewer are resolved on
 * every call. The titles of a search live in a WeakMap keyed by that search's query object, so they go with it.
 */
final class WorkflowRequestSearchDefinition extends EntitySearchDefinition
{
    /** Query alias of the request entity. */
    public const ALIAS = 'r';

    /** Subject type of the rows in SearchKit's host event. */
    public const SUBJECT_TYPE = 'workflow_request';

    /** The bundle's translation domain (the default group label). */
    public const TRANSLATION_DOMAIN = 'NowoWorkflowBundle';

    /** The longest title shown (characters). */
    public const MAX_TITLE_LENGTH = 200;

    /** The shape of a string request id in a stored recent (client input). */
    private const REFERENCE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    /** Doctrine types of integer id fields: a recent's id must then be digits within the int range. */
    private const INTEGER_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT];

    /** @var WeakMap<SearchQuery, array<string, string>> the resolved titles of one search, keyed by its query */
    private readonly WeakMap $titles;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly WorkflowRequestSearchGateInterface $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly WorkflowRequestSearchOptions $options,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?WorkflowRequestTitleResolverInterface $titleResolver = null,
        private readonly ?WorkflowRequestSearchNarrowerInterface $narrower = null,
    ) {
        $this->titles = new WeakMap();
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

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->gate->tenantOf($query->userIdentifier);
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->gate->isEnabled($tenant, $query->userIdentifier);
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
        if ($this->narrower instanceof WorkflowRequestSearchNarrowerInterface) {
            $this->narrow($this->narrower, $qb, $metadata, $tenant, $query);
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
        // SearchKit 1.9 has no batch hook for the shown rows: the titles of the visible candidates are resolved here,
        // in one call, never for a row the access event hid.
        $this->prepareItems($visible, $query);

        return $visible;
    }

    /**
     * Resolves the titles of these visible rows in one {@see WorkflowRequestTitleResolverInterface} call (nothing
     * without a resolver or without rows), for the toItem() calls of the same search.
     *
     * @param list<array<string, mixed>|object> $rows
     */
    public function prepareItems(array $rows, SearchQuery $query): void
    {
        if (!$this->titleResolver instanceof WorkflowRequestTitleResolverInterface || $rows === []) {
            return;
        }
        $ids  = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = $this->requestId($row);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $ids[]     = $id;
            }
        }
        $tenant = $this->gate->tenantOf($query->userIdentifier);
        $titles = $tenant === null ? [] : $this->titleResolver->titles($this->options->subject, $ids, $query->userIdentifier, $tenant, $query->locale);
        // @igor-ignore - keyed by the search's own query object: the entry goes with that query (WeakMap)
        $this->titles[$query] = $titles;
    }

    public function subjectId(array|object $row): string
    {
        return $this->requestId($row);
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $id = $this->requestId($row);
        if ($this->titleResolver instanceof WorkflowRequestTitleResolverInterface && !isset($this->titles[$query])) {
            $this->prepareItems([$row], $query);
        }
        $title = $this->titles[$query][$id] ?? ($this->options->titleField === null ? null : $this->value($row, $this->options->titleField));

        return new SearchItem(
            is_string($title) || is_int($title) ? mb_substr((string) $title, 0, self::MAX_TITLE_LENGTH) : '',
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
     * within the int range for an integer column, so PostgreSQL never sees "12abc"; else letters, digits, `_`, `-`).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters[$this->options->routeIdParameter] ?? null;
        if ($reference->route !== $this->options->route || !is_string($id)) {
            return null;
        }
        if (in_array($this->metadata()->getTypeOfField($this->options->idField), self::INTEGER_TYPES, true)) {
            return $this->isIntegerId($id) ? $id : null;
        }

        return preg_match(self::REFERENCE_ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** Digits only, no leading zero, at most PHP_INT_MAX. */
    private function isIntegerId(string $id): bool
    {
        if (!ctype_digit($id) || ($id !== '0' && ltrim($id, '0') !== $id)) {
            return false;
        }
        $max = (string) PHP_INT_MAX;

        return strlen($id) < strlen($max) || (strlen($id) === strlen($max) && $id <= $max);
    }

    /**
     * The host narrowing: every condition (fields ORed) ANDed to the query, values bound.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function narrow(WorkflowRequestSearchNarrowerInterface $narrower, QueryBuilder $qb, ClassMetadata $metadata, int|string $tenant, SearchQuery $query): void
    {
        $criteria = new WorkflowRequestSearchCriteria();
        $narrower->narrow($this->options->subject, $criteria, $query->userIdentifier, $tenant);
        if ($criteria->isNothing()) {
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
                $parameter = sprintf('workflow_request_narrow_%d_%d', $index, count($terms));
                $terms[]   = sprintf('%s IN (:%s)', $this->field($field), $parameter);
                $qb->setParameter($parameter, $values);
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
