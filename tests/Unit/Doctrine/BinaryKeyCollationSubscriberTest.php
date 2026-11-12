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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 1.15.2: NO PAD binary collations (PAD SPACE lesson): MariaDB `utf8mb4_nopad_bin`, MySQL 8.0.17+ `utf8mb4_0900_bin`,
 * older MySQL `utf8mb4_bin`; nothing on PostgreSQL / SQLite.
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
        $args = $this->args(WorkflowSubjectPin::class);
        (new BinaryKeyCollationSubscriber($this->registry(new MySQL80Platform(), ['serverVersion' => '8.0.16'])))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_bin', $args->getClassTable()->getColumn('subject_ref')->getCollation());
        self::assertSame('utf8mb4', $args->getClassTable()->getColumn('subject_ref')->getCharset());
        self::assertNull($args->getClassTable()->getColumn('other')->getCollation());

        $args = $this->args(WorkflowSubjectPin::class);
        (new BinaryKeyCollationSubscriber($this->registry(new MySQL80Platform(), ['primary' => ['serverVersion' => '8.0.40']])))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_0900_bin', $args->getClassTable()->getColumn('workflow_slug')->getCollation());
    }

    #[Test]
    public function withoutAConfiguredVersionTheServerIsAsked(): void
    {
        $args = $this->args(WorkflowDefinition::class);
        (new BinaryKeyCollationSubscriber($this->registry(new MySQL80Platform(), [], '8.0.12')))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_bin', $args->getClassTable()->getColumn('tenant_key')->getCollation());

        $args = $this->args(WorkflowDefinition::class);
        (new BinaryKeyCollationSubscriber($this->registry(new MySQL84Platform(), ['serverVersion' => ''], '8.4.0')))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_0900_bin', $args->getClassTable()->getColumn('slug')->getCollation());
    }

    #[Test]
    public function mariaDbNeverAsksTheServer(): void
    {
        $args = $this->args(WorkflowDefinition::class);
        (new BinaryKeyCollationSubscriber($this->registry(new MariaDBPlatform(), [], null)))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_nopad_bin', $args->getClassTable()->getColumn('tenant_key')->getCollation());
    }

    #[Test]
    public function anEntityWithoutKeyColumnsOrWithoutAManagerIsLeftAlone(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $args = $this->args(WorkflowDefinition::class);
        (new BinaryKeyCollationSubscriber($registry))->postGenerateSchemaTable($args);
        self::assertFalse($args->classTableWasMutated());

        $args = $this->args(WorkflowSubjectPin::class, ['other']);
        (new BinaryKeyCollationSubscriber($this->registry(new MySQL84Platform(), ['serverVersion' => '8.4.3'])))->postGenerateSchemaTable($args);
        self::assertFalse($args->classTableWasMutated(), 'no key column: the table is not replaced');
    }

    #[Test]
    public function olderDbalCollatesInPlace(): void
    {
        $table = new Table('t');
        $table->addColumn('tenant_key', 'string', ['length' => 128]);

        self::assertNull(BinaryKeyCollationSubscriber::collate($table, 'tenant_key', 'utf8mb4_nopad_bin', false));
        self::assertSame('utf8mb4_nopad_bin', $table->getColumn('tenant_key')->getPlatformOption('collation'));
        self::assertSame('utf8mb4', $table->getColumn('tenant_key')->getPlatformOption('charset'));
    }

    /**
     * @param class-string $class
     * @param list<string> $columns
     */
    private function args(string $class, array $columns = ['tenant_key', 'slug', 'workflow_slug', 'subject_ref', 'other']): GenerateSchemaTableEventArgs
    {
        $table = new Table('t');
        foreach ($columns as $column) {
            $table->addColumn($column, 'string', ['length' => 128]);
        }

        return new GenerateSchemaTableEventArgs(new ClassMetadata($class), new Schema(), $table);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function registry(AbstractPlatform $platform, array $params = [], ?string $reported = null): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        if ($reported === null) {
            $connection->expects(self::never())->method('getServerVersion');
        } else {
            $connection->method('getServerVersion')->willReturn($reported);
        }
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }
}
