<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\SearchKit;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchKitAbsentTestCase;
use Nowo\WorkflowBundle\Tests\Support\SearchKit\SearchRequestHarness;

use function class_alias;
use function class_exists;
use function getenv;
use function is_string;

if (!class_exists(EntitySearchProviderTestCase::class)) {
    class_alias(SearchKitAbsentTestCase::class, EntitySearchProviderTestCase::class);
}

/**
 * Since 1.21.0: SearchKit's provider contract (EntitySearchProviderTestCase) against the `workflow_requests` group.
 *
 * - Visible rows: shared requests of the tenant (no owner), which the host's access listener allows.
 * - Hidden rows: requests of another owner — the SQL keeps them, WorkflowRequestAccessCheckEvent hides them.
 * - Archived rows: `archived_at` set (filtered in SQL). Excluded statuses, the access event and the item are covered by
 *   WorkflowRequestSearchDefinitionTest.
 *
 * Budget: the search query and the host listener's one query for every candidate.
 */
abstract class WorkflowRequestSearchContractTestCase extends EntitySearchProviderTestCase
{
    protected SearchRequestHarness $harness;

    /** The DSN variable of the server, or null for SQLite in memory. */
    abstract protected static function urlVariable(): ?string;

    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
        $variable = static::urlVariable();
        $url      = $variable === null ? null : getenv($variable);
        if ($variable !== null && (!is_string($url) || $url === '')) {
            self::markTestSkipped($variable . ' is not set (real-database SearchKit contract).');
        }
        $this->harness = new SearchRequestHarness(is_string($url) ? $url : null);
    }

    protected function tearDown(): void
    {
        if (isset($this->harness)) {
            $this->harness->em->getConnection()->close();
        }
    }

    protected function definition(): EntitySearchDefinition
    {
        return $this->harness->definition();
    }

    protected function queryCounter(): QueryCounter
    {
        return $this->harness->counter;
    }

    protected function tenants(): array
    {
        return [7101, 7102];
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->harness->insert($tenant, 'Leave ' . $text, body: 'body ' . $text);
    }

    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        return $this->harness->insert($tenant, 'Leave ' . $text, owner: 'someone-else');
    }

    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        return $this->harness->insert($tenant, 'Leave ' . $text, archived: true);
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/requests/' . $id, 'workflow_requests', SearchRequestHarness::ROUTE, ['id' => (string) $id]);
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->harness->gate->tenant  = $tenant;
        $this->harness->gate->viewer  = $userIdentifier;
        $this->harness->gate->enabled = true;
    }

    protected function disableFeature(): void
    {
        $this->harness->gate->enabled = false;
    }

    protected function sensitiveTitles(): bool
    {
        return true;
    }
}
