<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strlen;

/**
 * Opaque tenant key of the host (an organization UUID, a workspace slug…), since 1.15.
 *
 * The bundle only compares keys byte for byte: no trimming, no case folding. A definition without a tenant is a
 * global template; a definition with a tenant is only ever used, listed, shown or edited for that exact key.
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
        if ($key === '' || strlen($key) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException(sprintf('A workflow tenant key must be 1 to %d bytes without control characters.', self::MAX_LENGTH));
        }
        $this->key = $key;
    }

    /**
     * The key stored in the `tenant_key` columns: the tenant's key, or the empty string for "no tenant" (global).
     */
    public static function storageKey(?self $tenant): string
    {
        return $tenant === null ? '' : $tenant->key;
    }

    /**
     * The tenant of a stored `tenant_key` value (null for the empty string, i.e. global).
     */
    public static function fromStorageKey(string $key): ?self
    {
        return $key === '' ? null : new self($key);
    }

    /**
     * Byte-for-byte equality; two "no tenant" values (null) are equal.
     */
    public static function same(?self $a, ?self $b): bool
    {
        return self::storageKey($a) === self::storageKey($b);
    }
}
