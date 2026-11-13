<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Renames bundle entity tables and constraints according to {@see TableNamePrefixer}.
 *
 * Since 1.15.2 a plain Doctrine listener tagged `doctrine.event_listener` for `loadClassMetadata` (it no longer
 * implements `EventSubscriber`): DoctrineBundle 3 does not register `doctrine.event_subscriber` services.
 */
final class TablePrefixSubscriber
{
    private const ENTITY_NAMESPACE = 'Nowo\\WorkflowBundle\\Entity\\';

    /**
     * Creates the subscriber with the table name prefixer.
     */
    public function __construct(
        private readonly TableNamePrefixer $tableNamePrefixer,
    ) {
    }

    /**
     * Applies the configured table prefix to the metadata of the bundle entities.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $this->tableNamePrefixer->applyToClassMetadata($metadata);
    }
}
