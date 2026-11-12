<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * Opaque tenant key of the host (an organization UUID, a workspace slug…), since 1.15.
 *
 * Printable ASCII without spaces (a UUID, a slug): no accent, no trailing space, so database collations cannot fold two
 * keys together (audit NO M1). Keys are compared exactly: the bundle re-checks every row in PHP (`acme` is not
 * `Acme`), and on MySQL / MariaDB the key columns use the binary collation. A definition without a tenant is a global
 * template; a definition with a tenant is only ever used, listed, shown or edited for that exact key.
 */
final readonly class WorkflowTenant
{
    /** Length of the `tenant_key` columns. */
    public const MAX_LENGTH = 128;

    public string $key;

    /**
     * @throws InvalidArgumentException when the key is empty, longer than 128 bytes or holds a control character
     */
    public function __construct(string $key)
    {
        if (preg_match('/^[\x21-\x7E]{1,' . self::MAX_LENGTH . '}$/D', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A workflow tenant key must be 1 to %d printable ASCII characters without spaces.', self::MAX_LENGTH));
        }
        $this->key = $key;
    }

    /**
     * The key stored in the `tenant_key` columns: the tenant's key, or the empty string for "no tenant" (global).
     */
    public static function storageKey(?self $tenant): string
    {
        return $tenant instanceof self ? $tenant->key : '';
    }

    /**
     * The tenant of a stored `tenant_key` value (null for the empty string, i.e. global).
     */
    public static function fromStorageKey(string $key): ?self
    {
        return $key === '' ? null : new self($key);
    }
}
