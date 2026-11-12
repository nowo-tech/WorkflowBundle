<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

use function array_fill;
use function count;
use function implode;
use function is_numeric;

/**
 * Reads and writes the version pins of in-flight subjects (since 1.15) with plain DBAL statements on the connection
 * of the definitions, so pinning never flushes a unit of work (neither the application's nor the bundle's private
 * one). Inside a transaction the application opened, the pin is part of it (rolled back with it).
 *
 * Stateless (table and column names are read from the mapping per call, table prefix included).
 *
 * @internal
 */
final readonly class SubjectPinStore
{
    private const SAVEPOINT = 'nowo_workflow_pin';

    public function __construct(
        private WorkflowDefinitionRepository $repository,
    ) {
    }

    /**
     * The id of the definition version the subject is pinned to, if any. The SQL comparison only pre-filters (a
     * case-insensitive collation also returns `Acme` for `acme`): tenant key, slug and reference are re-checked byte
     * for byte (audit NO M1).
     */
    public function pinnedDefinitionId(?WorkflowTenant $tenant, string $workflowSlug, WorkflowSubjectRef $subject): ?int
    {
        foreach ($this->rows($tenant, $subject, $workflowSlug) as $row) {
            return $row['definition'];
        }

        return null;
    }

    /**
     * Pins the subject on a definition version unless it is pinned already, and returns the version it is pinned to
     * (the first pin wins: a concurrent request may have pinned it on another version, audit NO M3).
     *
     * Inside an open transaction the insert runs in a savepoint, so a unique-constraint violation never aborts the
     * application's transaction (PostgreSQL); outside one it commits on its own (autocommit).
     */
    public function pin(?WorkflowTenant $tenant, string $workflowSlug, WorkflowSubjectRef $subject, WorkflowDefinition $definition): ?int
    {
        $existing = $this->pinnedDefinitionId($tenant, $workflowSlug, $subject);
        if ($existing !== null || $definition->getId() === null) {
            return $existing ?? $definition->getId();
        }

        $m          = $this->metadata();
        $connection = $this->repository->connection();
        $savepoint  = $connection->isTransactionActive() && $connection->getDatabasePlatform()->supportsSavepoints();
        if ($savepoint) {
            $connection->createSavepoint(self::SAVEPOINT);
        }

        try {
            $connection->insert($m['table'], [
                $m['tenant']     => WorkflowTenant::storageKey($tenant),
                $m['slug']       => $workflowSlug,
                $m['ref']        => $subject->id,
                $m['definition'] => $definition->getId(),
                $m['created']    => new DateTimeImmutable(),
            ], [
                $m['tenant']     => Types::STRING,
                $m['slug']       => Types::STRING,
                $m['ref']        => Types::STRING,
                $m['definition'] => Types::INTEGER,
                $m['created']    => Types::DATETIME_IMMUTABLE,
            ]);
            if ($savepoint) {
                $connection->releaseSavepoint(self::SAVEPOINT);
            }
        } catch (UniqueConstraintViolationException) {
            // Another request pinned it first: its pin wins.
            if ($savepoint) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
            }
        }

        return $this->pinnedDefinitionId($tenant, $workflowSlug, $subject) ?? $definition->getId();
    }

    /**
     * Removes the pins of a subject (all workflows), e.g. when the host deletes or anonymises the subject. Only the
     * rows of exactly that tenant key and reference are deleted (by id).
     *
     * @return int pins removed
     */
    public function forget(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        $m       = $this->metadata();
        $removed = 0;
        foreach ($this->rows($tenant, $subject, null) as $row) {
            $removed += (int) $this->repository->connection()->executeStatement('DELETE FROM ' . $m['table'] . ' WHERE ' . $m['id'] . ' = ?', [$row['id']]);
        }

        return $removed;
    }

    /**
     * The pins of a subject (of one workflow, or all), re-checked byte for byte.
     *
     * @return list<array{id: int, definition: int}>
     */
    private function rows(?WorkflowTenant $tenant, WorkflowSubjectRef $subject, ?string $workflowSlug): array
    {
        $m   = $this->metadata();
        $key = WorkflowTenant::storageKey($tenant);
        $sql = 'SELECT ' . $m['id'] . ' AS pin_id, ' . $m['definition'] . ' AS pin_definition, ' . $m['tenant'] . ' AS pin_tenant, ' . $m['slug'] . ' AS pin_slug, ' . $m['ref'] . ' AS pin_ref FROM ' . $m['table']
            . ' WHERE ' . $m['tenant'] . ' = ? AND ' . $m['ref'] . ' = ?' . ($workflowSlug !== null ? ' AND ' . $m['slug'] . ' = ?' : '') . ' ORDER BY ' . $m['id'];
        $params = $workflowSlug !== null ? [$key, $subject->id, $workflowSlug] : [$key, $subject->id];

        $rows = [];
        foreach ($this->repository->connection()->fetchAllAssociative($sql, $params) as $row) {
            if ($row['pin_tenant'] === $key && $row['pin_ref'] === $subject->id && ($workflowSlug === null || $row['pin_slug'] === $workflowSlug)
                && is_numeric($row['pin_id']) && is_numeric($row['pin_definition'])) {
                $rows[] = ['id' => (int) $row['pin_id'], 'definition' => (int) $row['pin_definition']];
            }
        }

        return $rows;
    }

    /**
     * How many subjects are pinned to these definition versions.
     *
     * @param list<int> $definitionIds
     */
    public function countForDefinitions(array $definitionIds): int
    {
        if ($definitionIds === []) {
            return 0;
        }
        $m = $this->metadata();

        return (int) $this->repository->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $m['table'] . ' WHERE ' . $m['definition'] . ' IN (' . implode(', ', array_fill(0, count($definitionIds), '?')) . ')',
            $definitionIds,
        );
    }

    /**
     * @return array{table: string, id: string, tenant: string, slug: string, ref: string, definition: string, created: string}
     */
    private function metadata(): array
    {
        $metadata = $this->repository->bundleMetadata(WorkflowSubjectPin::class);
        $platform = $this->repository->connection()->getDatabasePlatform();
        $quote    = static fn (string $name): string => $platform->quoteSingleIdentifier($name);

        return [
            'table'      => $metadata->getTableName(),
            'id'         => $quote($metadata->getColumnName('id')),
            'tenant'     => $quote($metadata->getColumnName('tenantKey')),
            'slug'       => $quote($metadata->getColumnName('workflowSlug')),
            'ref'        => $quote($metadata->getColumnName('subjectRef')),
            'definition' => $quote($metadata->getSingleAssociationJoinColumnName('definition')),
            'created'    => $quote($metadata->getColumnName('createdAt')),
        ];
    }
}
