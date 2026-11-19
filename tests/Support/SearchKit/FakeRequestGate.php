<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchGateInterface;

/**
 * The host's gate in the tests: the viewer's current tenant (only for the viewer acting now) and a feature switch.
 */
final class FakeRequestGate implements WorkflowRequestSearchGateInterface
{
    public int|string|null $tenant = null;

    public ?string $viewer = null;

    public bool $enabled = true;

    /** @var list<array{0: int|string, 1: string}> */
    public array $enabledCalls = [];

    public function tenantOf(string $userIdentifier): int|string|null
    {
        return $userIdentifier === $this->viewer ? $this->tenant : null;
    }

    public function isEnabled(int|string $tenant, string $userIdentifier): bool
    {
        $this->enabledCalls[] = [$tenant, $userIdentifier];

        return $this->enabled;
    }
}
