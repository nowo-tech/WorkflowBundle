<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Util\SubjectClass;

/**
 * The domain object a workflow applies to, given to the host's tenancy and guard ports (since 1.15).
 *
 * `object` is the host's own object (never a bundle or framework type); `class` is its real class (Doctrine proxies
 * resolved). Built per call and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class WorkflowSubject
{
    public string $class;

    /**
     * @param string $workflowSlug slug of the workflow being resolved or applied ('' when not known yet)
     */
    public function __construct(
        public object $object,
        public string $workflowSlug = '',
    ) {
        $this->class = SubjectClass::real($object);
    }
}
