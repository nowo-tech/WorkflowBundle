<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Index;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Service\SchemaSyncService;
use Nowo\WorkflowBundle\Tests\Support\SchemaSyncServiceWithoutSequenceListing;
use Nowo\WorkflowBundle\Tests\Support\TestDbalException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class SchemaSyncServiceTest extends TestCase
{
    public function testFilterExistingSchemaObjectsSkipsKnownObjects(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['workflow_definition']);
        $schemaManager->method('listTableIndexes')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $statements = [
            'CREATE TABLE workflow_definition (id INT)',
            'ALTER TABLE workflow_definition ADD foo VARCHAR(255)',
        ];

        $filtered = $service->filterExistingSchemaObjects($statements);

        self::assertSame(['ALTER TABLE workflow_definition ADD foo VARCHAR(255)'], $filtered);
    }

    public function testFilterCreateStatementsForExistingTables(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));

        $statements = [
            'CREATE TABLE workflow_definition (id INT)',
            'ALTER TABLE workflow_definition ADD foo VARCHAR(255)',
        ];

        $filtered = $service->filterCreateStatementsForExistingTables($statements, ['workflow_definition']);

        self::assertSame(['ALTER TABLE workflow_definition ADD foo VARCHAR(255)'], $filtered);
    }

    public function testIsDuplicateSchemaObjectException(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));

        self::assertTrue($service->isDuplicateSchemaObjectException(new RuntimeException('relation already exists')));
        self::assertTrue($service->isDuplicateSchemaObjectException(new RuntimeException('duplicate column name')));
        self::assertFalse($service->isDuplicateSchemaObjectException(new RuntimeException('syntax error')));
    }

    public function testFilterExistingSchemaObjectsSkipsExistingIndexes(): void
    {
        $index = new Index('uniq_workflow_definition_slug', ['slug']);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['workflow_definition']);
        $schemaManager->method('listTableIndexes')->willReturn(['uniq_workflow_definition_slug' => $index]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $statements = [
            'CREATE UNIQUE INDEX uniq_workflow_definition_slug ON workflow_definition (slug)',
            'ALTER TABLE workflow_definition ADD description TEXT',
        ];

        $filtered = $service->filterExistingSchemaObjects($statements);

        self::assertSame(['ALTER TABLE workflow_definition ADD description TEXT'], $filtered);
    }

    public function testFilterExistingSchemaObjectsSkipsExistingSequences(): void
    {
        $schemaManager = $this->getMockBuilder(AbstractSchemaManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listTableNames', 'listTableIndexes', 'listSequences'])
            ->getMockForAbstractClass();
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);
        $schemaManager->method('listSequences')->willReturn(['workflow_definition_id_seq']);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $statements = [
            'CREATE SEQUENCE workflow_definition_id_seq',
            'ALTER TABLE workflow_definition ADD priority INT',
        ];

        $filtered = $service->filterExistingSchemaObjects($statements);

        self::assertSame(['ALTER TABLE workflow_definition ADD priority INT'], $filtered);
    }

    public function testListExistingSequenceNamesReturnsEmptyWhenListSequencesFails(): void
    {
        $schemaManager = $this->getMockBuilder(AbstractSchemaManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listTableNames', 'listTableIndexes', 'listSequences'])
            ->getMockForAbstractClass();
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);
        $schemaManager->method('listSequences')->willThrowException(new RuntimeException('unsupported'));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        self::assertSame(
            ['CREATE SEQUENCE workflow_definition_id_seq'],
            $service->filterExistingSchemaObjects(['CREATE SEQUENCE workflow_definition_id_seq']),
        );
    }

    public function testIsDuplicateSchemaObjectExceptionDetectsDuplicateKeyMessage(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));

        self::assertTrue($service->isDuplicateSchemaObjectException(new RuntimeException('duplicate key value violates unique constraint')));
    }

    public function testFilterExistingSchemaObjectsKeepsAlterStatements(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $statements = [
            'ALTER TABLE workflow_definition ADD priority INT',
            'CREATE TABLE workflow_definition (id INT)',
        ];

        self::assertSame($statements, $service->filterExistingSchemaObjects($statements));
    }

    public function testFilterExistingSchemaObjectsSkipsSchemaQualifiedCreateTable(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['workflow_definition']);
        $schemaManager->method('listTableIndexes')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $filtered = $service->filterExistingSchemaObjects([
            'CREATE TABLE public.workflow_definition (id INT)',
            'ALTER TABLE workflow_definition ADD priority INT',
        ]);

        self::assertSame(['ALTER TABLE workflow_definition ADD priority INT'], $filtered);
    }

    public function testFilterExistingSchemaObjectsSkipsSchemaQualifiedCreateSequence(): void
    {
        $schemaManager = $this->getMockBuilder(AbstractSchemaManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listTableNames', 'listTableIndexes', 'listSequences'])
            ->getMockForAbstractClass();
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);
        $schemaManager->method('listSequences')->willReturn(['workflow_definition_id_seq']);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        $filtered = $service->filterExistingSchemaObjects([
            'CREATE SEQUENCE public.workflow_definition_id_seq',
            'ALTER TABLE workflow_definition ADD priority INT',
        ]);

        self::assertSame(['ALTER TABLE workflow_definition ADD priority INT'], $filtered);
    }

    public function testIsDuplicateSchemaObjectExceptionWalksPreviousExceptions(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));
        $root    = new RuntimeException('wrapper', 0, new RuntimeException('relation already exists'));

        self::assertTrue($service->isDuplicateSchemaObjectException($root));
    }

    public function testIsDuplicateSchemaObjectExceptionDetectsDbalExceptionCodes(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));

        self::assertTrue($service->isDuplicateSchemaObjectException(new TestDbalException(1050)));
        self::assertTrue($service->isDuplicateSchemaObjectException(new TestDbalException('42P07')));
    }

    public function testListExistingSequenceNamesReturnsEmptyWhenListSequencesIsUnavailable(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncServiceWithoutSequenceListing($em);

        self::assertSame(['CREATE SEQUENCE workflow_definition_id_seq'], $service->filterExistingSchemaObjects([
            'CREATE SEQUENCE workflow_definition_id_seq',
        ]));
    }

    public function testListExistingSequenceNamesReturnsEmptyWhenListingFails(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn([]);
        $schemaManager->method('listTableIndexes')->willReturn([]);
        $schemaManager->method('listSequences')->willThrowException(new RuntimeException('sequences unsupported'));

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $service = new SchemaSyncService($em);

        self::assertSame(['CREATE SEQUENCE workflow_definition_id_seq'], $service->filterExistingSchemaObjects([
            'CREATE SEQUENCE workflow_definition_id_seq',
        ]));
    }

    public function testSchemaManagerSupportsSequenceListing(): void
    {
        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));
        $method  = new ReflectionMethod(SchemaSyncService::class, 'schemaManagerSupportsSequenceListing');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, $this->createMock(AbstractSchemaManager::class)));
    }

    public function testExecuteStatementsCountsExecutedAndSkipped(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(
                1,
                self::throwException(new RuntimeException('relation already exists')),
            );

        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));
        $result  = $service->executeStatements($connection, [
            'CREATE TABLE workflow_definition (id INT)',
            'CREATE TABLE workflow_definition (id INT)',
        ]);

        self::assertSame(['executed' => 1, 'skipped' => 1], $result);
    }

    public function testExecuteStatementsRethrowsNonDuplicateErrors(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willThrowException(new RuntimeException('permission denied'));

        $service = new SchemaSyncService($this->createMock(EntityManagerInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permission denied');
        $service->executeStatements($connection, ['CREATE TABLE workflow_definition (id INT)']);
    }
}
