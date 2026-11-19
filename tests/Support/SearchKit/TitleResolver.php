<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nowo\WorkflowBundle\Contract\WorkflowRequestTitleResolverInterface;

use function array_map;

/**
 * The host's title resolver of subject "leave" in the tests: one query for the batch, "Leave: <title>"; records every
 * call so the tests can assert it only ever sees visible ids.
 */
final class TitleResolver implements WorkflowRequestTitleResolverInterface
{
    /** @var list<array{subject: string, ids: list<string>, user: string, tenant: int|string, locale: ?string}> */
    public array $calls = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function titles(string $subject, array $ids, string $userIdentifier, int|string $tenant, ?string $locale): array
    {
        $this->calls[] = ['subject' => $subject, 'ids' => $ids, 'user' => $userIdentifier, 'tenant' => $tenant, 'locale' => $locale];
        $titles        = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM wfsk_search_request WHERE id IN (?)', [array_map('intval', $ids)], [ArrayParameterType::INTEGER]) as $row) {
            if ($row['title'] !== null) {
                $titles[(string) $row['id']] = 'Leave: ' . $row['title'];
            }
        }

        return $titles;
    }
}
