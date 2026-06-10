<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

/**
 * Builds Symfony Workflow Definition objects from persisted entities.
 */
final class WorkflowDefinitionBuilder
{
    public function build(WorkflowDefinition $definition): Definition
    {
        $places = $definition->getPlaceNames();

        if ($places === []) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow "%s" has no places defined.',
                $definition->getSlug(),
            ));
        }

        $transitions = [];
        foreach ($definition->getTransitions() as $transition) {
            $transitions[] = new Transition(
                $transition->getName(),
                $transition->getFromPlaces(),
                $transition->getToPlaces(),
            );
        }

        return new Definition(
            $places,
            $transitions,
            $definition->getInitialPlace(),
            new DatabaseMetadataStore($definition),
        );
    }
}
