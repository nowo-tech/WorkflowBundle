<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Model\WorkflowRequestRef;

use function array_map;
use function sprintf;

/**
 * The host's access rule in the tests, as a real host writes it: one query for every candidate of the subject. A
 * request is visible to its owner, and to everyone in its tenant when it has no owner (a shared request).
 */
final class OwnerAccessListener
{
    public const TABLES = ['leave' => 'wfsk_search_request', 'purchase' => 'wfsk_purchase_request'];

    /** @var list<string> the subjects of the events seen */
    public array $subjects = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(WorkflowRequestAccessCheckEvent $event): void
    {
        $this->subjects[] = $event->subject;
        $ids              = array_map(static fn (WorkflowRequestRef $request): string => $request->id, $event->requests());
        $allowed          = $this->connection->fetchFirstColumn(
            sprintf("SELECT id FROM %s WHERE id IN (?) AND (owner_identifier = '' OR owner_identifier = ?)", self::TABLES[$event->subject]),
            [$event->subject === 'leave' ? array_map('intval', $ids) : $ids, $event->userIdentifier],
            [$event->subject === 'leave' ? ArrayParameterType::INTEGER : ArrayParameterType::STRING],
        );
        foreach ($allowed as $id) {
            $event->allow((string) $id);
        }
    }
}
