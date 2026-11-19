<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;

/**
 * Optional host port (since 1.21): extra SQL conditions for the viewer (`nowo_workflow.search_kit.subjects.<subject>.
 * narrower`), e.g. "requester = viewer OR approver = viewer".
 *
 * The candidate window is bounded (`max_candidates`, newest first) and the access event is deny by default, so a
 * viewer who sees only a few requests among many could find none of them in the window. Narrowing in SQL first keeps
 * their own requests in the window. The access event still decides every row: narrowing never shows a row.
 *
 * Only mapped fields and bound values (no SQL text). Called on every search and recent check; stateless.
 */
interface WorkflowRequestSearchNarrowerInterface
{
    /**
     * @param string $subject the subject key
     * @param WorkflowRequestSearchCriteria $criteria add conditions here (ANDed with the bundle's)
     * @param string $userIdentifier the signed-in user of the search
     * @param int|string $tenant the gate's tenant of the search
     */
    public function narrow(string $subject, WorkflowRequestSearchCriteria $criteria, string $userIdentifier, int|string $tenant): void;
}
