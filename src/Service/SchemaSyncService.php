<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Throwable;

use function in_array;

/**
 * Applies Doctrine schema create/update SQL for Workflow Bundle entities only.
 */
class SchemaSyncService
{
    private const ENTITY_NAMESPACE_PREFIX = 'Nowo\\WorkflowBundle\\Entity\\';

    /**
     * Creates the service for the entity manager that owns the bundle entities.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns the CREATE SQL of the bundle tables.
     *
     * @return list<string> SQL statements
     */
    public function getCreateSchemaSql(): array
    {
        $tool = new SchemaTool($this->entityManager);

        return $tool->getCreateSchemaSql($this->getBundleMetadata());
    }

    /**
     * Returns the additive update SQL of the bundle tables.
     *
     * @return list<string> SQL statements to update schema (additive)
     */
    public function getUpdateSchemaSql(): array
    {
        $tool = new SchemaTool($this->entityManager);

        return $tool->getUpdateSchemaSql($this->getBundleMetadata());
    }

    /**
     * Idempotent sync: CREATE missing objects, then ALTER/additive updates (filtered).
     *
     * @return list<string>
     */
    public function getSyncSchemaSql(): array
    {
        return $this->filterExistingSchemaObjects(array_merge(
            $this->getCreateMissingSchemaSql(),
            $this->getUpdateSchemaSql(),
        ));
    }

    /**
     * CREATE statements for bundle tables that are not in the database yet.
     *
     * @return list<string>
     */
    public function getCreateMissingSchemaSql(): array
    {
        return $this->filterExistingSchemaObjects(
            $this->getCreateSchemaSql(),
        );
    }

    /**
     * Drops statements that would create tables, indexes or sequences that already exist.
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    public function filterExistingSchemaObjects(array $statements): array
    {
        $tables    = array_flip($this->listExistingTableNames());
        $indexes   = array_flip($this->listExistingIndexNames());
        $sequences = array_flip($this->listExistingSequenceNames());
        $filtered  = [];

        foreach ($statements as $sql) {
            if ($this->shouldSkipStatement($sql, $tables, $indexes, $sequences)) {
                continue;
            }

            $filtered[] = $sql;
        }

        return $filtered;
    }

    /**
     * Drops CREATE statements of tables listed as already existing.
     *
     * @param list<string> $statements
     * @param list<string> $existingTables lower-case table names
     *
     * @return list<string>
     */
    public function filterCreateStatementsForExistingTables(array $statements, array $existingTables): array
    {
        $tables = array_flip($existingTables);

        return array_values(array_filter(
            $statements,
            fn (string $sql): bool => !$this->shouldSkipStatement($sql, $tables, [], []),
        ));
    }

    /**
     * Executes the statements, counting duplicate-object errors as skipped.
     *
     * @param list<string> $statements
     *
     * @return array{executed: int, skipped: int}
     */
    public function executeStatements(Connection $connection, array $statements): array
    {
        $executed = 0;
        $skipped  = 0;

        foreach ($statements as $sql) {
            try {
                $connection->executeStatement($sql);
                ++$executed;
            } catch (Throwable $e) {
                if ($this->isDuplicateSchemaObjectException($e)) {
                    ++$skipped;
                    continue;
                }

                throw $e;
            }
        }

        return ['executed' => $executed, 'skipped' => $skipped];
    }

    /**
     * Tells whether an exception (or one of its previous exceptions) reports an already existing schema object.
     */
    public function isDuplicateSchemaObjectException(Throwable $exception): bool
    {
        // PostgreSQL: duplicate table / column / object (constraint). MySQL: table, column, key name, foreign key name.
        $codes = ['42P07', '42701', '42710', '42S01', '1050', '1060', '1061', '1826'];
        $walk  = $exception;

        while ($walk instanceof Throwable) {
            if ($walk instanceof DbalException) {
                $code = (string) $walk->getCode();
                if (in_array($code, $codes, true)) {
                    return true;
                }
            }

            $message = strtolower($walk->getMessage());
            if (
                str_contains($message, 'already exists')
                || str_contains($message, 'duplicate key')
                || str_contains($message, 'duplicate column')
                || str_contains($message, 'duplicate foreign key')
            ) {
                return true;
            }

            $walk = $walk->getPrevious();
        }

        return false;
    }

    /**
     * @param array<string, int> $tables
     * @param array<string, int> $indexes
     * @param array<string, int> $sequences
     */
    private function shouldSkipStatement(
        string $sql,
        array $tables,
        array $indexes,
        array $sequences,
    ): bool {
        $table = $this->parseCreateTableName($sql);
        if ($table !== null && isset($tables[$table])) {
            return true;
        }

        $index = $this->parseCreateIndexName($sql);
        if ($index !== null && isset($indexes[$index])) {
            return true;
        }

        $sequence = $this->parseCreateSequenceName($sql);

        return $sequence !== null && isset($sequences[$sequence])

        ;
    }

    /**
     * @return list<string> lower-case table names
     */
    private function listExistingTableNames(): array
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();

        return array_map(
            static fn (string $name): string => strtolower($name),
            $schemaManager->listTableNames(),
        );
    }

    /**
     * @return list<string> lower-case index names
     */
    private function listExistingIndexNames(): array
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
        $names         = [];

        foreach ($schemaManager->listTableNames() as $table) {
            foreach ($schemaManager->listTableIndexes($table) as $index) {
                $names[] = strtolower($index->getName());
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string> lower-case sequence names
     */
    private function listExistingSequenceNames(): array
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();

        if (!$this->schemaManagerSupportsSequenceListing($schemaManager)) {
            return [];
        }

        try {
            /** @var list<Sequence> $sequences */
            $sequences = $schemaManager->listSequences();

            return array_map(
                static fn (Sequence $sequence): string => strtolower($sequence->getName()),
                $sequences,
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @internal
     */
    protected function schemaManagerSupportsSequenceListing(object $schemaManager): bool
    {
        return method_exists($schemaManager, 'listSequences');
    }

    private function parseCreateTableName(string $sql): ?string
    {
        if (!preg_match('/CREATE\s+TABLE\s+(?:(\w+)\.)?["\']?(\w+)["\']?/i', trim($sql), $matches)) {
            return null;
        }

        return strtolower($matches[2]);
    }

    private function parseCreateIndexName(string $sql): ?string
    {
        if (!preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:(\w+)\.)?["\']?(\w+)["\']?/i', trim($sql), $matches)) {
            return null;
        }

        return strtolower($matches[2]);
    }

    private function parseCreateSequenceName(string $sql): ?string
    {
        if (!preg_match('/CREATE\s+SEQUENCE\s+(?:(\w+)\.)?["\']?(\w+)["\']?/i', trim($sql), $matches)) {
            return null;
        }

        return strtolower($matches[2]);
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function getBundleMetadata(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        return array_values(array_filter(
            $metadata,
            static fn (ClassMetadata $classMetadata): bool => str_starts_with(
                $classMetadata->getName(),
                self::ENTITY_NAMESPACE_PREFIX,
            ),
        ));
    }
}
