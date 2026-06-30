<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Enum;

/**
 * Symfony Workflow definition type stored in the database.
 */
enum WorkflowType: string
{
    case StateMachine = 'state_machine';
    case Workflow     = 'workflow';

    public function label(): string
    {
        return match ($this) {
            self::StateMachine => 'State machine',
            self::Workflow     => 'Workflow',
        };
    }
}
