<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Form;

use function is_array;
use function is_string;

/**
 * Builds place choice lists for transition multiselect fields.
 */
final class PlaceChoiceHelper
{
    /**
     * @param list<string> $placeNames
     * @param list<string> $selected
     *
     * @return array<string, string>
     */
    public static function buildChoices(array $placeNames, array $selected = []): array
    {
        $all = array_values(array_unique(array_merge($placeNames, $selected)));
        sort($all);

        if ($all === []) {
            return [];
        }

        return array_combine($all, $all);
    }

    /**
     * @param list<array<string, mixed>> $places
     *
     * @return list<string>
     */
    public static function extractNamesFromSubmittedPlaces(array $places): array
    {
        $names = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $name = $place['name'] ?? '';
            if (!is_string($name)) {
                continue;
            }

            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
