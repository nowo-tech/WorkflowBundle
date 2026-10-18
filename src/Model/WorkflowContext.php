<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;
use Stringable;

use function array_key_exists;
use function get_debug_type;
use function is_bool;
use function is_scalar;
use function sprintf;

/**
 * Runtime lookup context used to resolve which workflow definition applies.
 */
final readonly class WorkflowContext
{
    /** @var array<string, string> */
    public array $parameters;

    /**
     * Parameter values are normalized to strings (match rules store strings): ints/floats are cast,
     * booleans become "true"/"false", Stringable objects are cast and null values are dropped.
     *
     * @param array<array-key, mixed> $parameters Matching parameters (e.g. tenant, document_type)
     *
     * @throws InvalidArgumentException when a value cannot be represented as a string (arrays, plain objects)
     */
    public function __construct(
        public ?string $subjectClass = null,
        array $parameters = [],
    ) {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[(string) $key] = $this->normalizeValue((string) $key, $value);
        }

        $this->parameters = $normalized;
    }

    public function get(string $key): ?string
    {
        return $this->parameters[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    /** @return array<string, string> */
    public function sortedParameters(): array
    {
        $parameters = $this->parameters;
        ksort($parameters);

        return $parameters;
    }

    public function withParameter(string $key, string $value): self
    {
        $parameters       = $this->parameters;
        $parameters[$key] = $value;

        return new self($this->subjectClass, $parameters);
    }

    public function withSubjectClass(string $subjectClass): self
    {
        return new self($subjectClass, $this->parameters);
    }

    private function normalizeValue(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('Workflow context parameter "%s" must be a scalar or Stringable value, "%s" given.', $key, get_debug_type($value)));
    }
}
