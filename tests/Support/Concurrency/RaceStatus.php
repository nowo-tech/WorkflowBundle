<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

enum RaceStatus: string
{
    case Draft  = 'draft';
    case Review = 'review';
    case Done   = 'done';
}
