<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

/**
 * Editable sections of a workflow definition form.
 */
enum WorkflowDefinitionFormSection: string
{
    case General     = 'general';
    case MatchRules  = 'match_rules';
    case Places      = 'places';
    case Transitions = 'transitions';

    /**
     * Returns the route name of the section editor.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::General     => 'nowo_workflow_definition_edit_general',
            self::MatchRules  => 'nowo_workflow_definition_edit_match_rules',
            self::Places      => 'nowo_workflow_definition_edit_places',
            self::Transitions => 'nowo_workflow_definition_edit_transitions',
        };
    }

    /**
     * Intro (help) translation key of the section on the edit page.
     */
    public function helpKey(): string
    {
        return match ($this) {
            self::General     => 'form.help.general',
            self::MatchRules  => 'form.help.match_rules_list',
            self::Places      => 'form.help.places_list',
            self::Transitions => 'form.help.transition_places',
        };
    }

    /**
     * Returns the page title translation key of the section.
     */
    public function titleKey(): string
    {
        return match ($this) {
            self::General     => 'page.edit_definition_general',
            self::MatchRules  => 'page.edit_definition_match_rules',
            self::Places      => 'page.edit_definition_places',
            self::Transitions => 'page.edit_definition_transitions',
        };
    }
}
