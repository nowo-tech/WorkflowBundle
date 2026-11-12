<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Contract;

use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;

/**
 * Host port (since 1.15): the whitelist of scalar facts transition conditions may read
 * (`nowo_workflow.guards.variables_provider`), e.g. `days <= 2 and type == 'vacation'`.
 *
 * Conditions never touch the subject object: they only see the values returned here. Without this port the condition
 * field is hidden in the editor and a transition that has a condition is always blocked (fail closed).
 */
interface WorkflowSubjectVariablesProviderInterface
{
    /**
     * Variable names conditions on definitions of this subject class may use (validated when a definition is saved).
     *
     * @param class-string $subjectClass
     *
     * @return list<string>
     */
    public function variableNames(string $subjectClass): array;

    /**
     * The values for one subject. A variable listed by variableNames() but missing here makes the condition false.
     */
    public function variablesOf(WorkflowSubject $subject): WorkflowSubjectVariables;
}
