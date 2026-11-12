<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectVariablesProviderInterface;

use function array_key_exists;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Whitelisted scalar facts of a subject that transition conditions may read (since 1.15), e.g.
 * `['days' => 2, 'type' => 'vacation']`, returned by
 * {@see WorkflowSubjectVariablesProviderInterface}.
 *
 * Only scalars and null: a condition can never reach an object, a method or a property of the subject.
 */
final readonly class WorkflowSubjectVariables
{
    /** Variable names: lower-case identifiers, as in the condition language. */
    public const NAME_PATTERN = '/^[a-z_][a-z0-9_]{0,63}$/D';

    /** Longest string value (longer values are refused). */
    public const MAX_STRING_LENGTH = 1024;

    /** @var array<string, bool|float|int|string|null> */
    private array $values;

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidArgumentException on an invalid name, a non-scalar value, NAN/INF or an over-long string
     */
    public function __construct(array $values)
    {
        $checked = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid workflow variable name "%s".', (string) $name));
            }
            if (!($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value))) {
                throw new InvalidArgumentException(sprintf('The workflow variable "%s" must be a scalar or null.', $name));
            }
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                throw new InvalidArgumentException(sprintf('The workflow variable "%s" must be a finite number.', $name));
            }
            if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                throw new InvalidArgumentException(sprintf('The workflow variable "%s" is longer than %d bytes.', $name, self::MAX_STRING_LENGTH));
            }
            $checked[$name] = $value;
        }
        $this->values = $checked;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): bool|float|int|string|null
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function all(): array
    {
        return $this->values;
    }
}
