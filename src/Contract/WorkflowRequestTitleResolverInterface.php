<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;

/**
 * Host port (since 1.21): the titles of the workflow requests a search shows (`nowo_workflow.search_kit.subjects.
 * <subject>.title_resolver`), e.g. "Ana García — Holiday, 3–7 March" for a leave request.
 *
 * Called at most once per search page, in one batch, and only with ids the viewer may see (after the bundle's
 * {@see WorkflowRequestAccessCheckEvent}); never with an id the access event hid. Load
 * the titles in one query. A title may depend on the viewer (e.g. "Absent" instead of a medical leave type); never
 * put a body, comment, justification, attachment or approver note in it. The bundle caps it to 200 characters and
 * SearchKit escapes it. Stateless: nothing kept between calls (FrankenPHP worker mode).
 */
interface WorkflowRequestTitleResolverInterface
{
    /**
     * @param string $subject the subject key (`search_kit.subjects.<subject>`), to tell request types apart
     * @param list<string> $ids the shown ids (the subject's `id_field` values, as strings)
     * @param string $userIdentifier the signed-in user of the search
     * @param int|string $tenant the gate's tenant of the search
     * @param string|null $locale the search locale
     *
     * @return array<string, string> id => title; an id left out shows the generic translated title
     *                               (`search.untitled_request`), never the `title_field` column (fail closed)
     */
    public function titles(string $subject, array $ids, string $userIdentifier, int|string $tenant, ?string $locale): array;
}
