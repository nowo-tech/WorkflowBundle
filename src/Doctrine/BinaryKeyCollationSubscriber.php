<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Psr\Log\LoggerInterface;

use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;
use function preg_match;
use function version_compare;

/**
 * Keys are compared exactly (since 1.15, audit NO M1 / NO-v N3 / N7): on MySQL / MariaDB the key columns of the bundle
 * tables (`tenant_key`, `slug`, `workflow_slug`, `subject_ref`) get a binary collation, so `acme` and `Acme` (or `acme`
 * and `acmé` under an accent-insensitive collation) are different keys in queries and unique indexes.
 *
 * Since 1.15.2 the collation is also NO PAD (PAD SPACE lesson, same as AuthKitBundle 1.46.0): `utf8mb4_bin` on MySQL 8
 * and MariaDB's `_bin` collations ignore trailing spaces (`'abc '` = `'abc'`). MariaDB gets `utf8mb4_nopad_bin`, MySQL
 * 8.0.17+ `utf8mb4_0900_bin`; older MySQL servers keep `utf8mb4_bin` (the only binary utf8mb4 collation they have). The
 * bundle already refuses keys with spaces and re-checks every key with `===`: this is defence in depth.
 *
 * Applied when the schema is generated from the mapping (`doctrine:migrations:diff`, `doctrine:schema:update`,
 * `nowo:workflow:sync-schema`), whatever metadata the cache holds: the platform is the one of the manager that maps
 * the entities and is read then (it needs the database, or `doctrine.dbal.server_version`, as schema generation does
 * anyway; a failure is not swallowed). Other platforms (PostgreSQL, SQLite) already compare these strings exactly and
 * get no collation (a `collation` option is not portable). The repositories re-check every key in PHP anyway.
 *
 * Since 1.15.2 it is a plain Doctrine listener tagged `doctrine.event_listener` for `postGenerateSchemaTable`:
 * DoctrineBundle 3 no longer registers `doctrine.event_subscriber` services, so 1.15.0 / 1.15.1 never applied it there.
 *
 * @internal
 */
final readonly class BinaryKeyCollationSubscriber
{
    /** The fallback binary collation (MySQL < 8.0.17); PAD SPACE. */
    public const COLLATION = 'utf8mb4_bin';

    /** MySQL 8.0.17+ binary NO PAD collation. */
    public const MYSQL_NOPAD_COLLATION = 'utf8mb4_0900_bin';

    /** MariaDB binary NO PAD collation. */
    public const MARIADB_NOPAD_COLLATION = 'utf8mb4_nopad_bin';

    /** First MySQL release with `utf8mb4_0900_bin`. */
    public const MYSQL_NOPAD_SINCE = '8.0.17';

    /** @var array<class-string, list<non-empty-string>> column names */
    private const COLUMNS = [
        WorkflowDefinition::class => ['tenant_key', 'slug'],
        WorkflowSubjectPin::class => ['tenant_key', 'workflow_slug', 'subject_ref'],
    ];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The exact-match collation for that platform and server version, null when the platform already compares exactly.
     *
     * A MySQL 8 platform with an unknown or unparsable server version gets `utf8mb4_0900_bin`; a version without a
     * patch number (`8.0`) is read as `8.0.0` and keeps the `utf8mb4_bin` fallback.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB_NOPAD_COLLATION,
            $platform instanceof MySQL80Platform       => self::supportsMysqlNoPad($serverVersion) ? self::MYSQL_NOPAD_COLLATION : self::COLLATION,
            $platform instanceof AbstractMySQLPlatform => self::COLLATION,
            default                                    => null,
        };
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $class   = $args->getClassMetadata()->getName();
        $columns = self::COLUMNS[$class] ?? [];
        $manager = $columns === [] ? null : $this->doctrine->getManagerForClass($class);
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }
        $connection = $manager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if ($class === WorkflowDefinition::class) {
            $this->warnAboutPartialVersion($connection, $platform);
        }
        $collation  = self::collationFor($platform, $platform instanceof MySQL80Platform ? self::serverVersion($connection) : null);
        if ($collation === null) {
            return;
        }

        $original = $table = $args->getClassTable();
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (older doctrine/dbal / doctrine/orm)
        $editor = method_exists($table, 'edit') && method_exists($args, 'setClassTable');
        foreach ($columns as $column) {
            if ($table->hasColumn($column)) {
                $table = self::collate($table, $column, $collation, $editor) ?? $table;
            }
        }
        if ($table !== $original) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }

    /**
     * Since 1.15.2: one warning per schema generation when a MySQL server version is configured without its patch
     * number and that leaves the key columns on `utf8mb4_bin`, which is PAD SPACE (`'abc '` = `'abc'`): `8` (DBAL picks
     * a generic platform) or `8.0` (8.0.17+ cannot be told apart). `9.7` or `8.4` are fine.
     */
    private function warnAboutPartialVersion(Connection $connection, AbstractPlatform $platform): void
    {
        if (!$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform) {
            return;
        }
        $version = self::configuredVersion($connection);
        if ($version === null || preg_match('/^\d+\.\d+\.\d+/', $version) === 1 || self::collationFor($platform, $version) !== self::COLLATION) {
            return;
        }
        $this->logger?->warning('doctrine.dbal.server_version "{version}" has no patch number: the Workflow key columns get the PAD SPACE collation utf8mb4_bin. Configure the full version (e.g. 8.4.3) to get utf8mb4_0900_bin.', ['bundle' => 'NowoWorkflowBundle', 'version' => $version]);
    }

    /**
     * The server version configured on the connection (also on a primary / replica connection), if any.
     */
    private static function configuredVersion(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $primary = $params['primary'] ?? null;
        $version = $params['serverVersion'] ?? (is_array($primary) ? ($primary['serverVersion'] ?? null) : null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The configured server version (`doctrine.dbal.server_version` / `?serverVersion=`, also on a primary / replica
     * connection), else the one the server reports (null when it cannot be read: unknown).
     */
    private static function serverVersion(Connection $connection): ?string
    {
        $version = self::configuredVersion($connection);
        if ($version !== null) {
            return $version;
        }
        // doctrine/dbal 4: public Connection::getServerVersion(); doctrine/dbal 3 keeps it private (the wrapped driver
        // connection reports it).
        if (is_callable([$connection, 'getServerVersion'])) {
            return $connection->getServerVersion();
        }

        return self::wrappedServerVersion($connection); // @codeCoverageIgnore
    }

    /**
     * doctrine/dbal 3 only.
     *
     * @codeCoverageIgnore
     */
    private static function wrappedServerVersion(Connection $connection): ?string
    {
        $driver  = method_exists($connection, 'getWrappedConnection') ? $connection->getWrappedConnection() : null;
        $version = is_object($driver) && is_callable([$driver, 'getServerVersion']) ? $driver->getServerVersion() : null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function supportsMysqlNoPad(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $serverVersion, $match) !== 1) {
            return true;
        }

        $version = $match[1] . '.' . (($match[2] ?? '') !== '' ? $match[2] : '0') . '.' . (($match[3] ?? '') !== '' ? $match[3] : '0');

        return version_compare($version, self::MYSQL_NOPAD_SINCE, '>=');
    }
}
