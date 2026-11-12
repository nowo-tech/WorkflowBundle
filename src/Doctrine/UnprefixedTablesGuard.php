<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use RuntimeException;

use function implode;
use function in_array;
use function sprintf;
use function strtolower;

/**
 * Since 1.15.2: stops schema generation (`doctrine:migrations:diff`, `doctrine:schema:update`,
 * `nowo:workflow:sync-schema`) when `nowo_workflow.table_prefix` is set but the bundle tables still have their default
 * names and the prefixed ones do not exist yet: DoctrineBundle 3 hosts on 1.15.0 / 1.15.1 created them unprefixed
 * (the prefix listener was ignored there). A generated diff would drop the existing tables and create empty ones; the
 * error lists the RENAME statements to run first.
 *
 * Nothing happens with the default prefix, or when the database cannot be read (no connection while generating SQL
 * offline): there is then nothing to compare.
 *
 * @internal
 */
final readonly class UnprefixedTablesGuard
{
    /** The bundle tables with the default prefix. */
    public const DEFAULT_TABLES = ['workflow_definition', 'workflow_place', 'workflow_transition', 'workflow_match_rule', 'workflow_subject_pin'];

    public function __construct(
        private TableNamePrefixer $prefixer,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        if ($this->prefixer->getTablePrefix() === TableNamePrefixer::DEFAULT_PREFIX) {
            return;
        }
        $schema = $args->getSchema();
        $pairs  = [];
        foreach (self::DEFAULT_TABLES as $table) {
            $prefixed = $this->prefixer->applyPrefix($table);
            if ($schema->hasTable($prefixed)) {
                $pairs[$table] = $prefixed;
            }
        }
        if ($pairs === []) {
            return;
        }

        $connection = $args->getEntityManager()->getConnection();
        try {
            $existing = [];
            foreach ($connection->createSchemaManager()->listTableNames() as $name) {
                $existing[] = strtolower($name);
            }
        } catch (DbalException) {
            return;
        }

        $renames = [];
        foreach ($pairs as $table => $prefixed) {
            if (in_array($table, $existing, true) && !in_array(strtolower($prefixed), $existing, true)) {
                $renames[] = self::renameSql($connection->getDatabasePlatform(), $table, $prefixed);
            }
        }
        if ($renames === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "nowo_workflow.table_prefix is \"%s\", but the Workflow tables still have their default names (created while DoctrineBundle 3 ignored the prefix, WorkflowBundle 1.15.0 / 1.15.1). A generated migration would DROP them and create empty ones. Back up the database, rename them first, then generate the migration again:\n%s",
            $this->prefixer->getTablePrefix(),
            implode("\n", $renames),
        ));
    }

    private static function renameSql(AbstractPlatform $platform, string $from, string $to): string
    {
        return $platform instanceof AbstractMySQLPlatform
            ? sprintf('RENAME TABLE %s TO %s;', $from, $to)
            : sprintf('ALTER TABLE %s RENAME TO %s;', $from, $to);
    }
}
