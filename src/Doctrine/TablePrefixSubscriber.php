<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

/**
 * Renames bundle entity tables and constraints according to {@see TableNamePrefixer}.
 */
final class TablePrefixSubscriber implements EventSubscriber
{
    private const ENTITY_NAMESPACE = 'Nowo\\WorkflowBundle\\Entity\\';

    public function __construct(
        private readonly TableNamePrefixer $tableNamePrefixer,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $this->tableNamePrefixer->applyToClassMetadata($metadata);
    }
}
