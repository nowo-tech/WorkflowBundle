<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowDefinitionSequence;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 1.15.2: NO PAD binary collations (PAD SPACE lesson): MariaDB `utf8mb4_nopad_bin`, MySQL 8.0.17+ `utf8mb4_0900_bin`,
 * older MySQL `utf8mb4_bin`; nothing on PostgreSQL / SQLite. 1.22.1: applied on `postGenerateSchema`, by table name.
 */
final class BinaryKeyCollationSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: string|null, 2: string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), '10.11.9-MariaDB', 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.4' => [new MySQL84Platform(), '8.4.3', 'utf8mb4_0900_bin'];
        yield 'MySQL 9.7 without patch' => [new MySQL84Platform(), '9.7', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.0.17' => [new MySQL80Platform(), '8.0.17', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.0.16' => [new MySQL80Platform(), '8.0.16', 'utf8mb4_bin'];
        yield 'MySQL 8.0 without patch' => [new MySQL80Platform(), '8.0', 'utf8mb4_bin'];
        yield 'MySQL 8 unknown version' => [new MySQL80Platform(), null, 'utf8mb4_0900_bin'];
        yield 'MySQL 8 unparsable version' => [new MySQL80Platform(), 'latest', 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), '5.7.44', 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), '16', null];
        yield 'SQLite' => [new SQLitePlatform(), null, null];
    }

    #[Test]
    #[DataProvider('platforms')]
    public function theCollationFollowsThePlatformAndServerVersion(AbstractPlatform $platform, ?string $version, ?string $expected): void
    {
        self::assertSame($expected, BinaryKeyCollationSubscriber::collationFor($platform, $version));
    }

    #[Test]
    public function theConfiguredServerVersionDecidesOnMysql8(): void
    {
        $args = $this->args(new MySQL80Platform(), ['serverVersion' => '8.0.16']);
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame('utf8mb4_bin', $this->column($args, 'workflow_subject_pin', 'subject_ref')->getCollation());
        self::assertSame('utf8mb4', $this->column($args, 'workflow_subject_pin', 'subject_ref')->getCharset());
        self::assertNull($this->column($args, 'workflow_subject_pin', 'other')->getCollation());

        $args = $this->args(new MySQL80Platform(), ['primary' => ['serverVersion' => '8.0.40']]);
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame('utf8mb4_0900_bin', $this->column($args, 'workflow_subject_pin', 'workflow_slug')->getCollation());
    }

    #[Test]
    public function withoutAConfiguredVersionTheServerIsAsked(): void
    {
        $args = $this->args(new MySQL80Platform(), [], '8.0.12');
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame('utf8mb4_bin', $this->column($args, 'workflow_definition', 'tenant_key')->getCollation());

        $args = $this->args(new MySQL84Platform(), ['serverVersion' => ''], '8.4.0');
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame('utf8mb4_0900_bin', $this->column($args, 'workflow_definition', 'slug')->getCollation());
    }

    #[Test]
    public function mariaDbNeverAsksTheServer(): void
    {
        $args = $this->args(new MariaDBPlatform(), [], null);
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame('utf8mb4_nopad_bin', $this->column($args, 'workflow_definition', 'tenant_key')->getCollation());
    }

    #[Test]
    public function aManagerWithoutTheBundleEntitiesOrAPlatformWithoutCollationIsLeftAlone(): void
    {
        $args   = $this->args(new MariaDBPlatform(), [], null, transient: true);
        $schema = $args->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema());

        $args   = $this->args(new PostgreSQLPlatform(), [], null);
        $schema = $args->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema());

        $args   = $this->args(new MariaDBPlatform(), [], null, columns: ['other']);
        $schema = $args->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema(), 'no key column: the schema is not replaced');

        $args   = $this->args(new MariaDBPlatform(), [], null, tables: false);
        $schema = $args->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema(), 'tables not generated (schema filter): nothing to collate');
    }

    #[Test]
    public function aMysqlServerVersionWithoutPatchIsWarnedAboutOncePerSchema(): void
    {
        $logger = new RecordingLogger();
        foreach ([[new MySQL80Platform(), '8.0', 1], [new MySQLPlatform(), '8', 1], [new MySQL84Platform(), '8.4.3', 0], [new MySQL84Platform(), '9.7', 0], [new MariaDBPlatform(), '11.4', 0], [new MySQL84Platform(), null, 0]] as [$platform, $version, $warnings]) {
            $logger->records = [];
            $listener        = new BinaryKeyCollationSubscriber(null, $logger);
            $listener->postGenerateSchema($this->args($platform, $version === null ? [] : ['serverVersion' => $version], $version === null ? '8.4.3' : null));
            self::assertCount($warnings, $logger->records, (string) $version);
        }
        self::assertStringContainsString('has no patch number', $this->warn($logger));
    }

    private function warn(RecordingLogger $logger): string
    {
        $listener = new BinaryKeyCollationSubscriber(null, $logger);
        $listener->postGenerateSchema($this->args(new MySQL80Platform(), ['serverVersion' => '8.0']));

        return $logger->records[0]['message'] ?? '';
    }

    #[Test]
    public function collatesInPlaceOrThroughTheEditor(): void
    {
        $table = new Table('t');
        $table->addColumn('tenant_key', 'string', ['length' => 128]);

        self::assertNull(BinaryKeyCollationSubscriber::collate($table, 'tenant_key', 'utf8mb4_nopad_bin', false));
        self::assertSame('utf8mb4_nopad_bin', $table->getColumn('tenant_key')->getPlatformOption('collation'));
        self::assertSame('utf8mb4', $table->getColumn('tenant_key')->getPlatformOption('charset'));

        $edited = BinaryKeyCollationSubscriber::collate($table, 'tenant_key', 'utf8mb4_0900_bin', true);
        self::assertInstanceOf(Table::class, $edited);
        self::assertSame('utf8mb4_0900_bin', $edited->getColumn('tenant_key')->getCollation());
    }

    private function column(GenerateSchemaEventArgs $args, string $table, string $column): Column
    {
        return $args->getSchema()->getTable($table)->getColumn($column);
    }

    /**
     * The schema tool's event for the two bundle tables (each with `$columns`) on a manager with that platform.
     *
     * @param array<string, mixed> $params
     * @param list<string> $columns
     */
    private function args(AbstractPlatform $platform, array $params = [], ?string $reported = null, bool $transient = false, array $columns = ['tenant_key', 'slug', 'workflow_slug', 'subject_ref', 'other'], bool $tables = true): GenerateSchemaEventArgs
    {
        $schemaTables = [];
        foreach ($tables ? ['workflow_definition', 'workflow_subject_pin'] : [] as $name) {
            $table = new Table($name);
            foreach ($columns as $column) {
                $table->addColumn($column, 'string', ['length' => 128]);
            }
            $schemaTables[] = $table;
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        if ($reported === null) {
            $connection->expects(self::never())->method('getServerVersion');
        } else {
            $connection->method('getServerVersion')->willReturn($reported);
        }
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn($transient);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $manager->method('getClassMetadata')->willReturnCallback(static function (string $class): ClassMetadata {
            /** @var class-string $class */
            $metadata = new ClassMetadata($class);
            $metadata->setPrimaryTable(['name' => match ($class) {
                WorkflowDefinition::class         => 'workflow_definition',
                WorkflowDefinitionSequence::class => 'workflow_definition_sequence',
                default                           => 'workflow_subject_pin',
            }]);

            return $metadata;
        });

        return new GenerateSchemaEventArgs($manager, new Schema($schemaTables));
    }
}
