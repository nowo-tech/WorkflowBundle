<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;

use function is_array;

/**
 * Applies the configured table prefix to bundle entity metadata.
 */
final class TableNamePrefixer
{
    public const DEFAULT_PREFIX = 'workflow_';

    /**
     * Creates the prefixer with the configured table prefix.
     */
    public function __construct(
        private readonly string $tablePrefix = self::DEFAULT_PREFIX,
    ) {
    }

    /**
     * Returns the configured table prefix.
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Replaces the default prefix of a table / constraint identifier with the configured one.
     */
    public function applyPrefix(string $identifier): string
    {
        if ($this->tablePrefix === self::DEFAULT_PREFIX) {
            return $identifier;
        }

        return str_replace(self::DEFAULT_PREFIX, $this->tablePrefix, $identifier);
    }

    /**
     * Renames the primary table, indexes, unique constraints and join tables of an entity mapping.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function applyToClassMetadata(ClassMetadata $metadata): void
    {
        if ($this->tablePrefix === self::DEFAULT_PREFIX) {
            return;
        }

        $tableName = $metadata->getTableName();
        $prefixed  = $this->applyPrefix($tableName);

        if ($prefixed !== $tableName) {
            $metadata->setPrimaryTable(array_merge($metadata->table ?? [], ['name' => $prefixed]));
        }

        $this->renameNamedEntries($metadata, 'uniqueConstraints');
        $this->renameNamedEntries($metadata, 'indexes');
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function renameNamedEntries(ClassMetadata $metadata, string $key): void
    {
        if (!isset($metadata->table[$key]) || !is_array($metadata->table[$key])) {
            return;
        }

        $renamed = [];
        foreach ($metadata->table[$key] as $name => $definition) {
            $renamed[$this->applyPrefix((string) $name)] = $definition;
        }

        if ($key === 'indexes') {
            /* @var array<string, mixed> $renamed */
            $metadata->table['indexes'] = $renamed;

            return;
        }

        if ($key === 'uniqueConstraints') {
            /* @var array<string, mixed> $renamed */
            $metadata->table['uniqueConstraints'] = $renamed;
        }
    }
}
