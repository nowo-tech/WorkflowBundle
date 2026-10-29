<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\WorkflowBundle\Command\SyncSchemaCommand;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncSchemaCommandTest extends TestCase
{
    public function testExecuteFailsWhenManagerIsNotOrm(): void
    {
        $manager = $this->createMock(ObjectManager::class);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('default')->willReturn($manager);

        $tester = new CommandTester(new SyncSchemaCommand($registry, 'default'));

        $this->expectException(RuntimeException::class);
        $tester->execute([]);
    }

    public function testExecuteReportsUpToDateWhenNoStatements(): void
    {
        $connection      = $this->createMock(Connection::class);
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn([]);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $tester = new CommandTester(new SyncSchemaCommand($registry, 'default'));
        $code   = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testExecuteRunsStatementsAndReportsCount(): void
    {
        $em = IntegrationEntityManagerFactory::createInMemory();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $tester = new CommandTester(new SyncSchemaCommand($registry, 'default'));
        $code   = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Executed', $tester->getDisplay());
        self::assertStringContainsString('SQL statement', $tester->getDisplay());
    }

    public function testExecuteSkipsDuplicateSchemaObjects(): void
    {
        $em = IntegrationEntityManagerFactory::createSyncedInMemory();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $tester = new CommandTester(new SyncSchemaCommand($registry, 'default'));
        $code   = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertTrue(
            str_contains($tester->getDisplay(), 'up to date')
            || str_contains($tester->getDisplay(), 'Skipped'),
            $tester->getDisplay(),
        );
    }

    public function testExecuteLogsStartAndSuccessWithCounts(): void
    {
        $em = IntegrationEntityManagerFactory::createInMemory();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $logger = new RecordingLogger();

        (new CommandTester(new SyncSchemaCommand($registry, 'default', $logger)))->execute([]);

        self::assertSame(['info: Workflow schema sync started.', 'info: Workflow schema sync finished.'], $logger->lines());
        $context = $logger->records[1]['context'];
        self::assertSame('sync_schema', $context['action'] ?? null);
        self::assertSame('default', $context['connection'] ?? null);
        self::assertGreaterThan(0, $context['executed'] ?? 0);
        self::assertIsInt($context['skipped'] ?? null);
    }

    public function testExecuteLogsAndRethrowsFailures(): void
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willThrowException(new RuntimeException('metadata unavailable'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $logger = new RecordingLogger();
        $tester = new CommandTester(new SyncSchemaCommand($registry, 'default', $logger));

        try {
            $tester->execute([]);
            self::fail('The sync failure must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('metadata unavailable', $exception->getMessage());
        }

        self::assertSame(['info: Workflow schema sync started.', 'error: Workflow schema sync failed.'], $logger->lines());
        self::assertInstanceOf(RuntimeException::class, $logger->records[1]['context']['exception'] ?? null);
    }
}
