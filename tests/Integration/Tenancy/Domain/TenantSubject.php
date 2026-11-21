<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain;

/**
 * What the host tenancy ports of the tests read from a subject: its organization (the tenant) and its UUID (the
 * subject reference).
 */
interface TenantSubject
{
    public function getUuid(): string;

    public function getOrganization(): string;
}
