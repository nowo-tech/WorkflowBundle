<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\Internal\HiddenValues;

use function array_values;
use function in_array;
use function is_object;
use function is_string;

/**
 * Framework-free view of the authenticated user of a Workflow UI request, given to a
 * {@see WorkflowUiAccessPolicyInterface} inside a {@see WorkflowUiAccessCheck} (since 1.14, REQ-ARCH-001 rule 3: no
 * Symfony `UserInterface` or `TokenInterface` in a public port).
 *
 * Its properties hold the user identifier, the security token's role names (as the token gives them, before any role
 * hierarchy) and the identifier of the impersonating user when the token is a switch-user token. The user object
 * ({@see user()}) is **not** a property: it lives in an internal {@see \WeakMap} keyed by this object, so a dump,
 * `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the VO never shows a password hash or another
 * field of the user, and a `clone` or an unserialized copy has no user.
 *
 * Role checks that must follow the role hierarchy or other voters belong to the Symfony authorization checker of the
 * policy's own adapter, not to {@see hasRole()}.
 *
 * Built by the bundle per request and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class WorkflowActor
{
    /** Bag of the user object. */
    private const USER = 'user';

    /** @var list<string> */
    public array $roles;

    /**
     * @param string $userIdentifier the user's identifier
     * @param array<int, string> $roles the security token's role names
     * @param object|null $user the host's user object (hidden, see the class comment)
     * @param string|null $impersonatorIdentifier identifier of the original user of a switch-user token
     */
    public function __construct(
        public string $userIdentifier,
        array $roles = [],
        ?object $user = null,
        public ?string $impersonatorIdentifier = null,
    ) {
        $this->roles = array_values($roles);
        HiddenValues::put($this, [self::USER => [self::USER => $user]]);
    }

    /**
     * The host's user object (the security token user), or null on a clone or an unserialized copy.
     */
    public function user(): ?object
    {
        $user = HiddenValues::get($this, self::USER, self::USER);

        return is_object($user) ? $user : null;
    }

    /** Whether the token holds this role name (no role hierarchy). */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** True when the token is a switch-user token (an impersonation). */
    public function isImpersonated(): bool
    {
        return is_string($this->impersonatorIdentifier);
    }
}
