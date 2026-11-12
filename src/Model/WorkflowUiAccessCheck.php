<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Internal\HiddenValues;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Framework-free view of a Workflow UI request, given to a {@see WorkflowUiAccessPolicyInterface} (since 1.14,
 * REQ-ARCH-001 rule 3: no Symfony `Request`, `TokenInterface` or `UserInterface` in a public port).
 *
 * Its properties hold the HTTP method, the matched route name (`_route`), the controller (`_controller`, a string) and
 * the authenticated user ({@see WorkflowActor}, null for an anonymous request). The path ({@see path()}) and the scalar
 * route parameters ({@see routeParam()}) are **not** properties: they live in an internal {@see \WeakMap} keyed by this
 * object, so a dump, `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the VO never shows them, and a
 * `clone` or an unserialized copy has none. Cookies, headers, the query string, the body and the session are never
 * copied.
 *
 * Built by the bundle for one decision and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class WorkflowUiAccessCheck
{
    /** Bag of the path. */
    private const PATH = 'path';

    /** Bag of the scalar route parameters. */
    private const ROUTE = 'route';

    /**
     * @param string $method HTTP method (upper case)
     * @param string|null $routeName the matched route (`_route`)
     * @param string|null $controller the controller (`_controller`) when it is a string
     * @param WorkflowActor|null $actor the authenticated user, null when anonymous
     * @param string $path path info, no query string (hidden, see the class comment)
     * @param array<array-key, mixed> $routeParams route parameters; only scalar values with a string name are kept (hidden)
     */
    public function __construct(
        public string $method,
        public ?string $routeName = null,
        public ?string $controller = null,
        public ?WorkflowActor $actor = null,
        string $path = '',
        array $routeParams = [],
    ) {
        $kept = [];
        foreach ($routeParams as $name => $value) {
            if (is_string($name) && ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                $kept[$name] = $value;
            }
        }

        HiddenValues::put($this, [self::PATH => [self::PATH => $path], self::ROUTE => $kept]);
    }

    /** True when the request has an authenticated user. */
    public function isAuthenticated(): bool
    {
        return $this->actor instanceof WorkflowActor;
    }

    /** The path info (no query string); an empty string on a clone or an unserialized copy. */
    public function path(): string
    {
        $path = HiddenValues::get($this, self::PATH, self::PATH);

        return is_string($path) ? $path : '';
    }

    /** A scalar route parameter (`/definitions/{id}` → `routeParam('id')`), or null. */
    public function routeParam(string $name): bool|float|int|string|null
    {
        return $this->scalar(HiddenValues::get($this, self::ROUTE, $name));
    }

    /** True when the route has this scalar parameter. */
    public function hasRouteParam(string $name): bool
    {
        return HiddenValues::has($this, self::ROUTE, $name);
    }

    /**
     * Every scalar route parameter.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function routeParams(): array
    {
        $params = [];
        foreach (HiddenValues::all($this, self::ROUTE) as $name => $value) {
            $params[$name] = $this->scalar($value);
        }

        return $params;
    }

    private function scalar(mixed $value): bool|float|int|string|null
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? $value : null;
    }
}
