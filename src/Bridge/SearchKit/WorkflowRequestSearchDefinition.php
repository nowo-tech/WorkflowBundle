<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Bridge\SearchKit;

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
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stringable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_int;
use function is_object;
use function is_string;
use function mb_substr;
use function preg_match;
use function sprintf;

/**
 * Since 1.21.0: the SearchKit group `workflow_requests` (SearchKit >= 1.9, {@see EntitySearchDefinition}) — the
 * host's workflow requests (e.g. leave requests) by title and status, only those the viewer may see.
 *
 * - Tenant and feature switch: the host's {@see WorkflowRequestSearchGateInterface}, asked on every search.
 * - SQL first ({@see baseQuery()}): the tenant field, archived rows (`archived_field IS NOT NULL`) and excluded
 *   statuses are filtered in the query; the newest first.
 * - Then `view`: one {@see WorkflowRequestAccessCheckEvent} for all candidates ({@see filterVisible()}), **deny by
 *   default**, so the host's access rules decide (SearchKit's own host event runs after it).
 * - A result shows the title and the status label only: never the body, comments, approver notes or any other field.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the entity manager, the tenant and the viewer are resolved on
 * every call; nothing is kept between calls.
 */
final class WorkflowRequestSearchDefinition extends EntitySearchDefinition
{
    /** Query alias of the request entity. */
    public const ALIAS = 'r';

    /** Subject type of the rows in SearchKit's host event. */
    public const SUBJECT_TYPE = 'workflow_request';

    /** The bundle's translation domain (the group label). */
    public const TRANSLATION_DOMAIN = 'NowoWorkflowBundle';

    /** The longest title shown (characters). */
    public const MAX_TITLE_LENGTH = 200;

    /** The shape of a request id in a stored recent (client input). */
    private const REFERENCE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly WorkflowRequestSearchGateInterface $gate,
        private readonly UrlGeneratorInterface $urls,
        private readonly WorkflowRequestSearchOptions $options,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function group(): string
    {
        return $this->options->group;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translator?->trans('search.group.workflow_requests', [], self::TRANSLATION_DOMAIN, $query->locale) ?? 'Requests';
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
        return [$this->field($this->options->titleField), $this->field($this->options->statusField)];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        $options  = $this->options;
        $metadata = $this->metadata();
        /** @var class-string $class the configured request entity (its metadata was just read) */
        $class = $options->subjectClass;
        $order = $options->orderField ?? $options->idField;
        $qb    = $this->entityManager()->createQueryBuilder()
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
        foreach ([$options->idField, $options->titleField, $options->statusField, $options->tenantField, $options->archivedField, $order] as $field) {
            if ($field !== null && !$metadata->hasField($field)) {
                throw new LogicException(sprintf('nowo_workflow.search_kit: "%s" is not a mapped field of %s.', $field, $options->subjectClass));
            }
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
        $event = new WorkflowRequestAccessCheckEvent($this->options->subjectClass, $query->userIdentifier, $tenant, $refs);
        $this->dispatcher->dispatch($event);

        $visible = [];
        foreach ($rows as $index => $row) {
            if ($event->isVisible($refs[$index]->id)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    public function subjectId(array|object $row): string
    {
        return $this->requestId($row);
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $title = $this->value($row, $this->options->titleField);
        $title = is_string($title) || is_int($title) ? mb_substr((string) $title, 0, self::MAX_TITLE_LENGTH) : '';

        return new SearchItem(
            $title,
            $this->urls->generate($this->options->route, [$this->options->routeIdParameter => $this->requestId($row)] + $this->options->routeParameters),
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

    /** A stored recent is client input: it must be this group's route, with the id parameter only in the id shape. */
    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters[$this->options->routeIdParameter] ?? null;

        return $reference->route === $this->options->route && is_string($id) && preg_match(self::REFERENCE_ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** The label of a status: its translation when `status_label_domain` is set, else the place name. */
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
     * The status place name (Doctrine gives a backed enum field as its value).
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
            throw new LogicException(sprintf('nowo_workflow.search_kit.subject_class %s is not managed by a Doctrine ORM entity manager.', $this->options->subjectClass));
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
