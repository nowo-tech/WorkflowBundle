<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use function array_key_exists;

/**
 * Runtime lookup context used to resolve which workflow definition applies.
 */
final readonly class WorkflowContext
{
    /**
     * @param array<string, string> $parameters Matching parameters (e.g. tenant, document_type)
     */
    public function __construct(
        public ?string $subjectClass = null,
        public array $parameters = [],
    ) {
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
}
