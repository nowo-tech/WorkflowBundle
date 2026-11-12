<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Throwable;

/**
 * Tenant keys and subject references are compared exactly (since 1.15, audit NO M1): on MySQL / MariaDB their columns
 * get the binary collation `utf8mb4_bin`, so `acme` and `Acme` (or `acme` and `acmé` under an accent-insensitive
 * collation) are different keys in queries and unique indexes. Other platforms already compare these strings exactly
 * (PostgreSQL, SQLite) and get no collation option (a `collation` option is not portable).
 *
 * The repositories re-check every key in PHP anyway; this only keeps the database from returning or colliding rows of
 * another key. When the platform cannot be determined (no database at cache warm-up), nothing is changed.
 *
 * @internal
 */
final class BinaryKeyCollationSubscriber implements EventSubscriber
{
    public const COLLATION = 'utf8mb4_bin';

    /** @var array<class-string, list<string>> */
    private const FIELDS = [
        WorkflowDefinition::class => ['tenantKey'],
        WorkflowSubjectPin::class => ['tenantKey', 'subjectRef'],
    ];

    public function getSubscribedEvents(): array
    {
        return [Events::loadClassMetadata];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $fields   = self::FIELDS[$metadata->getName()] ?? [];
        if ($fields === []) {
            return;
        }

        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable) {
            return;
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }

        foreach ($fields as $field) {
            if (isset($metadata->fieldMappings[$field])) {
                $metadata->fieldMappings[$field]->options = ['collation' => self::COLLATION] + ($metadata->fieldMappings[$field]->options ?? []);
            }
        }
    }
}
