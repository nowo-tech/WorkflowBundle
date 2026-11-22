<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowDefinitionCatalogInterface;

use function max;

/**
 * One page of {@see WorkflowDefinitionCatalogInterface::list()} (since 2.1): read models only, never the
 * `WorkflowDefinition` aggregate.
 */
final readonly class WorkflowDefinitionPage
{
    /**
     * @param list<WorkflowDefinitionView> $items the definitions of this page, ordered by name
     * @param int $total definitions the tenant owns (one per slug)
     * @param int $page 1-based page number actually returned (clamped into 1..$pages)
     * @param int $pages number of pages, at least 1
     * @param int $pageSize items per page requested
     *
     * @throws InvalidArgumentException when a counter is out of range
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pages,
        public int $pageSize,
    ) {
        if ($total < 0 || $page < 1 || $pages < 1 || $pageSize < 1) {
            throw new InvalidArgumentException('A workflow definition page needs a non-negative total and a positive page, page count and page size.');
        }
    }

    /**
     * An empty page.
     */
    public static function empty(int $pageSize): self
    {
        return new self([], 0, 1, 1, max(1, $pageSize));
    }
}
