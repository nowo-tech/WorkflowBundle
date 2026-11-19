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
 * 1.21): framework-free, mapped fields and bound values only. Every call adds one condition; conditions are ANDed;
 * inside one {@see whereAny()} call the fields are ORed. Created for one search and never reused.
 */
final class WorkflowRequestSearchCriteria
{
    /** The most values of one field (bounds the bound parameters). */
    public const MAX_VALUES = 1000;

    /** @var list<array<string, list<int|string>>> */
    private array $conditions = [];

    private bool $nothing = false;

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
     * @throws InvalidArgumentException for an invalid field name, no field, or too many values
     */
    public function whereAny(array $fieldValues): self
    {
        if ($fieldValues === []) {
            throw new InvalidArgumentException('A search condition needs at least one field.');
        }
        $condition = [];
        foreach ($fieldValues as $field => $values) {
            $values = is_array($values) ? array_values($values) : [$values];
            if (preg_match(WorkflowRequestSearchOptions::FIELD_PATTERN, $field) !== 1 || count($values) > self::MAX_VALUES) {
                throw new InvalidArgumentException(sprintf('Invalid search condition on "%s".', $field));
            }
            $condition[$field] = $values;
        }
        // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
        $this->conditions[] = $condition;

        return $this;
    }

    /** The viewer finds nothing in this subject. */
    public function nothing(): self
    {
        // @igor-ignore - per-search criteria object, created for one search and never reused (not a service)
        $this->nothing = true;

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

    /** Whether {@see nothing()} was called. */
    public function isNothing(): bool
    {
        return $this->nothing;
    }
}
