<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

/**
 * The marking of {@see SearchRequest} (a backed enum, as a host's state machine often stores it).
 */
enum SearchRequestStatus: string
{
    case Draft    = 'draft';
    case Pending  = 'pending';
    case Approved = 'approved';
}
