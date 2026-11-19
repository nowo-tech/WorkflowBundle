<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Search;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;

use function array_values;
use function count;
use function is_array;
use function preg_match;
use function sprintf;

/**
 * Conditions a {@see WorkflowRequestSearchNarrowerInterface} adds to one search (since
 * 1.21): framework-free, scalar mapped fields and bound values only. Every call adds one condition; conditions are
 * ANDed; inside one {@see whereAny()} call the fields are ORed. Created for one search and never reused.
 *
 * - {@see none()}: the viewer may see nothing — the search gives no group before any SQL runs.
 * - `whereIn($field, [])`: that field matches nothing (it compiles to `1 = 0` in the query).
 * - Long lists: the definition splits a field's values into IN lists of {@see CHUNK} values, ORed, each one its own
 *   bound parameter. More than the subject's `narrower_max_values` values in one field, or more than
 *   {@see MAX_TOTAL_VALUES} values in all, fails closed: no group, no SQL and a logged warning, never a silently cut
 *   list.
 */
final class WorkflowRequestSearchCriteria
{
    /** Values of one IN list (one bound array parameter). */
    public const CHUNK = 1000;

    /** Default most values of one field (`narrower_max_values`). */
    public const DEFAULT_MAX_VALUES = 10000;

    /** The most values of all fields together (bound parameters of one query stay under the 65535 of PostgreSQL). */
    public const MAX_TOTAL_VALUES = 60000;

    /** @var list<array<string, list<int|string>>> */
    private array $conditions = [];

    private bool $none = false;

    private int $total = 0;

    /** @var list<string> fields given more values than allowed */
    private array $overflow = [];

    /**
     * @param int $maxValues the most values of one field (the subject's `narrower_max_values`)
     */
    public function __construct(public readonly int $maxValues = self::DEFAULT_MAX_VALUES)
    {
    }

    /**
     * The field holds one of the values. An empty list matches nothing.
     *
     * @param array<int|string> $values the values (keys are ignored)
     */
    public function whereIn(string $field, array $values): self
    {
        return $this->whereAny([$field => $values]);
    }

    /**
     * At least one field holds its value (or one of its values), e.g. `['requesterId' => 5, 'approverId' => 5]`.
     *
     * @param array<string, array<int|string>|int|string> $fieldValues
     *
     * @throws InvalidArgumentException for an invalid field name or no field (a programming error)
     */
    public function whereAny(array $fieldValues): self
    {
        if ($fieldValues === []) {
            throw new InvalidArgumentException('A search condition needs at least one field.');
        }
        $condition = [];
        foreach ($fieldValues as $field => $values) {
            if (preg_match(WorkflowRequestSearchOptions::FIELD_PATTERN, $field) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid search condition on "%s".', $field));
            }
            $values = is_array($values) ? array_values($values) : [$values];
            // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
            $this->total += count($values);
            if (count($values) > $this->maxValues) {
                // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
                $this->overflow[] = $field;
            }
            $condition[$field] = $values;
        }
        // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
        $this->conditions[] = $condition;

        return $this;
    }

    /** The viewer may see nothing of this subject: no group, and no SQL runs. */
    public function none(): self
    {
        // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
        $this->none = true;

        return $this;
    }

    /**
     * The conditions, in order: each one maps fields (ORed) to their values.
     *
     * @return list<array<string, list<int|string>>>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** Whether {@see none()} was called. */
    public function isNone(): bool
    {
        return $this->none;
    }

    /** The values of every condition together (bound parameters of the query). */
    public function totalValues(): int
    {
        return $this->total;
    }

    /** Whether one field has more than {@see $maxValues} values, or all of them more than {@see MAX_TOTAL_VALUES}. */
    public function exceedsLimits(): bool
    {
        return $this->overflow !== [] || $this->total > self::MAX_TOTAL_VALUES;
    }

    /**
     * The fields given more than {@see $maxValues} values (the search then fails closed).
     *
     * @return list<string>
     */
    public function overflowingFields(): array
    {
        return $this->overflow;
    }
}
