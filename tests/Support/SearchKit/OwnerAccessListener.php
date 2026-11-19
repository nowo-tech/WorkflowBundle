<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;

use function array_map;

/**
 * The host's access rule in the tests, as a real host writes it: one query for every candidate. A request is visible
 * to its owner, and to everyone in its tenant when it has no owner (a shared request).
 */
final class OwnerAccessListener
{
    public int $calls = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(WorkflowRequestAccessCheckEvent $event): void
    {
        ++$this->calls;
        $ids     = array_map(static fn (WorkflowRequestRef $request): int => (int) $request->id, $event->requests());
        $allowed = $this->connection->fetchFirstColumn(
            "SELECT id FROM wfsk_search_request WHERE id IN (?) AND (owner_identifier = '' OR owner_identifier = ?)",
            [$ids, $event->userIdentifier],
            [ArrayParameterType::INTEGER],
        );
        foreach ($allowed as $id) {
            $event->allow((string) $id);
        }
    }
}
