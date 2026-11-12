<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tenancy;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

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
    public function __construct(
        private WorkflowDefinitionRepository $repository,
    ) {
    }

    /**
     * The id of the definition version the subject is pinned to, if any.
     */
    public function pinnedDefinitionId(?WorkflowTenant $tenant, string $workflowSlug, WorkflowSubjectRef $subject): ?int
    {
        $m  = $this->metadata();
        $id = $this->repository->connection()->fetchOne(
            'SELECT ' . $m['definition'] . ' FROM ' . $m['table'] . ' WHERE ' . $m['tenant'] . ' = ? AND ' . $m['slug'] . ' = ? AND ' . $m['ref'] . ' = ?',
            [WorkflowTenant::storageKey($tenant), $workflowSlug, $subject->id],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Pins the subject on a definition version unless it is pinned already (the first pin wins).
     */
    public function pin(?WorkflowTenant $tenant, string $workflowSlug, WorkflowSubjectRef $subject, WorkflowDefinition $definition): void
    {
        if ($definition->getId() === null || $this->pinnedDefinitionId($tenant, $workflowSlug, $subject) !== null) {
            return;
        }

        $m = $this->metadata();
        $this->repository->connection()->insert($m['table'], [
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
    }

    /**
     * Removes the pins of a subject (all workflows), e.g. when the host deletes or anonymises the subject.
     *
     * @return int pins removed
     */
    public function forget(WorkflowSubjectRef $subject, ?WorkflowTenant $tenant): int
    {
        $m = $this->metadata();

        return (int) $this->repository->connection()->executeStatement(
            'DELETE FROM ' . $m['table'] . ' WHERE ' . $m['tenant'] . ' = ? AND ' . $m['ref'] . ' = ?',
            [WorkflowTenant::storageKey($tenant), $subject->id],
        );
    }

    /**
     * Removes the pins on a definition version (before it is deleted).
     *
     * @return int pins removed
     */
    public function forgetDefinition(int $definitionId): int
    {
        $m = $this->metadata();

        return (int) $this->repository->connection()->executeStatement(
            'DELETE FROM ' . $m['table'] . ' WHERE ' . $m['definition'] . ' = ?',
            [$definitionId],
        );
    }

    /**
     * @return array{table: string, tenant: string, slug: string, ref: string, definition: string, created: string}
     */
    private function metadata(): array
    {
        $metadata = $this->repository->bundleMetadata(WorkflowSubjectPin::class);
        $platform = $this->repository->connection()->getDatabasePlatform();
        $quote    = static fn (string $name): string => $platform->quoteSingleIdentifier($name);

        return [
            'table'      => $metadata->getTableName(),
            'tenant'     => $quote($metadata->getColumnName('tenantKey')),
            'slug'       => $quote($metadata->getColumnName('workflowSlug')),
            'ref'        => $quote($metadata->getColumnName('subjectRef')),
            'definition' => $quote($metadata->getSingleAssociationJoinColumnName('definition')),
            'created'    => $quote($metadata->getColumnName('createdAt')),
        ];
    }
}
