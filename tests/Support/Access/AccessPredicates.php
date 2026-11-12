<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use LogicException;

use function in_array;
use function str_contains;
use function str_starts_with;

/**
 * Host access rules of the equivalence matrix, written once over plain values so the same rule can sit behind the
 * deprecated port (fed from the Symfony request and token) and behind the 1.14 port (fed from the VO).
 */
final class AccessPredicates
{
    public const NAMES = ['editors_get_only', 'definitions_no_delete', 'not_id_13', 'non_impersonated_admin', 'anonymous_only'];

    /**
     * @param list<string> $roles
     * @param array<string, bool|float|int|string|null> $routeParams
     */
    public static function decide(string $name, string $method, ?string $route, string $path, array $routeParams, ?string $user, array $roles, ?string $impersonator): bool
    {
        return match ($name) {
            'editors_get_only'       => in_array('ROLE_EDITOR', $roles, true) && $method === 'GET',
            'definitions_no_delete'  => $user !== null && str_starts_with($path, '/workflow/definitions') && !str_contains($path, '/delete'),
            'not_id_13'              => $user !== null && ($routeParams['id'] ?? null) !== '13' && $route !== null,
            'non_impersonated_admin' => in_array('ROLE_ADMIN', $roles, true) && $impersonator === null,
            'anonymous_only'         => $user === null,
            default                  => throw new LogicException('Unknown predicate ' . $name),
        };
    }
}
