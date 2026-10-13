<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\__CG__\Nowo\WorkflowBundle\Tests\Support\Subject;

use Nowo\WorkflowBundle\Tests\Support\Subject\WorkflowSubjectBase as RealSubject;

/**
 * Mimics a legacy Doctrine proxy class name ("…\__CG__\<RealClass>") extending the real entity.
 */
final class WorkflowSubjectBase extends RealSubject
{
}
