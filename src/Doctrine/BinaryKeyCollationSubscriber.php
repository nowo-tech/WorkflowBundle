<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;

use function method_exists;

/**
 * Keys are compared exactly (since 1.15, audit NO M1 / NO-v N3 / N7): on MySQL / MariaDB the key columns of the bundle
 * tables (`tenant_key`, `slug`, `workflow_slug`, `subject_ref`) get the binary collation `utf8mb4_bin`, so `acme` and
 * `Acme` (or `acme` and `acmé` under an accent-insensitive collation) are different keys in queries and unique indexes.
 *
 * Applied when the schema is generated from the mapping (`doctrine:migrations:diff`, `doctrine:schema:update`,
 * `nowo:workflow:sync-schema`), whatever metadata the cache holds: the platform is the one of the manager that maps
 * the entities and is read then (it needs the database, or `doctrine.dbal.server_version`, as schema generation does
 * anyway; a failure is not swallowed). Other platforms (PostgreSQL, SQLite) already compare these strings exactly and
 * get no collation (a `collation` option is not portable). The repositories re-check every key in PHP anyway.
 *
 * @internal
 */
final readonly class BinaryKeyCollationSubscriber implements EventSubscriber
{
    public const COLLATION = 'utf8mb4_bin';

    /** @var array<class-string, list<string>> column names */
    private const COLUMNS = [
        WorkflowDefinition::class => ['tenant_key', 'slug'],
        WorkflowSubjectPin::class => ['tenant_key', 'workflow_slug', 'subject_ref'],
    ];

    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [ToolEvents::postGenerateSchemaTable];
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $class   = $args->getClassMetadata()->getName();
        $columns = self::COLUMNS[$class] ?? [];
        $manager = $columns === [] ? null : $this->doctrine->getManagerForClass($class);
        if (!$manager instanceof EntityManagerInterface || !$manager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $table = $args->getClassTable();
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (older doctrine/dbal / doctrine/orm)
        if (!method_exists($table, 'edit') || !method_exists($args, 'setClassTable')) {
            // doctrine/dbal < 4.5 or doctrine/orm without schema editors: the mutable column API.
            foreach ($columns as $column) { // @codeCoverageIgnoreStart
                if ($table->hasColumn($column)) {
                    $table->getColumn($column)->setPlatformOption('collation', self::COLLATION);
                }
            }

            return; // @codeCoverageIgnoreEnd
        }

        $editor = $table->edit();
        foreach ($columns as $column) {
            if ($table->hasColumn($column)) {
                $editor->modifyColumnByUnquotedName($column, static function (ColumnEditor $editor): void {
                    $editor->setCollation(self::COLLATION);
                });
            }
        }
        $args->setClassTable($editor->create());
    }
}
