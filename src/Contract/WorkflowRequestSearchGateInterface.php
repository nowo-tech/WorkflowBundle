<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;

/**
 * Host port (since 1.21): the tenant and the feature switch of the SearchKit group of the workflow requests
 * (`nowo_workflow.search_kit.gate`). Asked on every search, never cached by the bundle (FrankenPHP worker mode), so
 * it must be cheap and stateless.
 *
 * Whether the viewer may see each request is not decided here: the bundle dispatches
 * {@see WorkflowRequestAccessCheckEvent} (deny by default) for the candidates of a search.
 */
interface WorkflowRequestSearchGateInterface
{
    /**
     * The value of the requests' tenant field (`search_kit.tenant_field`) the viewer searches now, e.g. the id of the
     * organization they are working in; null = no results. Without a tenant field any non-null value searches every
     * request (the access event still decides).
     *
     * @param string $userIdentifier the signed-in user of the search
     */
    public function tenantOf(string $userIdentifier): int|string|null;

    /**
     * Whether the viewer may search the requests of that tenant at all (the feature is on, the viewer is a member…).
     *
     * @param int|string $tenant what {@see tenantOf()} returned
     * @param string $userIdentifier the signed-in user of the search
     */
    public function isEnabled(int|string $tenant, string $userIdentifier): bool;
}
