<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Tenancy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Exception;
use LogicException;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Audit\AuditMiddleware;
use Nowo\WorkflowBundle\Contract\WorkflowTransitionPermissionCheckerInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\EventListener\TransitionGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\WorkflowUiRouteContextSubscriber;
use Nowo\WorkflowBundle\EventListener\WorkflowUnitOfWorkSubscriber;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowPinConflictException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SplObjectStorage;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Workflow\Definition as WorkflowShape;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Unit coverage of the audit NO fixes: concurrent pins (M3), collisions (L3), binary collation (M1), audit middleware
 * connections (L1), router context cleanup (L2) and failing guard ports (Info).
 */
final class AuditNoUnitTest extends TestCase
{
    #[Test]
    public function aConcurrentPinWinsAndTheTransactionSurvives(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [],
            [['pin_id' => 7, 'pin_definition' => 3, 'pin_tenant' => 'org-a', 'pin_slug' => 'leave', 'pin_ref' => 'leave-1']],
            [],
        );
        $connection->method('insert')->willThrowException($this->uniqueViolation());
        $connection->expects(self::once())->method('createSavepoint');
        $connection->expects(self::once())->method('rollbackSavepoint');
        $connection->expects(self::never())->method('releaseSavepoint');

        $definition = $this->definitionWithId(9);
        $store      = new SubjectPinStore($this->repository($connection));

        self::assertSame(3, $store->pin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), $definition), 'the first pin wins');
        self::assertNull($store->pin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), new WorkflowDefinition('X', 'leave', 'a', 'X')), 'nothing to pin without an id');
    }

    #[Test]
    public function aPinOutsideATransactionCommitsOnItsOwn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [],
            [['pin_id' => 1, 'pin_definition' => 9, 'pin_tenant' => 'ORG-A', 'pin_slug' => 'leave', 'pin_ref' => 'leave-1']],
        );
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::never())->method('createSavepoint');

        $store = new SubjectPinStore($this->repository($connection));
        self::assertSame(0, $store->countForDefinitions([]));
        self::assertSame(9, $store->pin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), $this->definitionWithId(9)), 'a case variant row is not the pin');
    }

    #[Test]
    public function draftAndCloneCollisionsEndWithTheExistingRow(): void
    {
        $existing = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        $existing->startAsDraft(new WorkflowTenant('org-a'));
        $published = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        $published->addPlace(new WorkflowPlace('a'));
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findDraft')->willReturnOnConsecutiveCalls(null, $existing, null, null);
        $repository->method('nextVersion')->willReturn(2);
        $repository->method('save')->willThrowException($this->uniqueViolation());
        $repository->method('findCurrent')->willReturn($published);
        $repository->method('existsInScope')->willReturn(false);
        $versioning = new WorkflowVersioning($repository);

        self::assertSame($existing, $versioning->createDraftOf($published), 'a concurrent request created the draft');
        self::assertSame($existing, $versioning->createDraftOf($existing), 'a draft is its own draft');
        try {
            $versioning->createDraftOf($published);
            self::fail('No draft to fall back to.');
        } catch (UniqueConstraintViolationException) {
        }
        try {
            $versioning->cloneTemplate('leave', new WorkflowTenant('org-a'));
            self::fail('Already customized concurrently.');
        } catch (LogicException $exception) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }

        $open = $this->createMock(WorkflowDefinitionRepository::class);
        $open->method('findDraft')->willReturn($existing);
        $open->expects(self::never())->method('save');
        self::assertSame($existing, (new WorkflowVersioning($open))->createDraftOf($published), 'the open draft of the scope');

        $this->expectException(LogicException::class);
        $versioning->publish($published);
    }

    #[Test]
    public function keyColumnsGetTheBinaryCollationOnMysqlOnly(): void
    {
        foreach (['workflow_definition' => ['tenant_key', 'slug'], 'workflow_subject_pin' => ['tenant_key', 'workflow_slug', 'subject_ref']] as $table => $columns) {
            $args = $this->schemaArgs(new MySQLPlatform(), $table, [...$columns, 'other']);
            (new BinaryKeyCollationSubscriber())->postGenerateSchema($args);
            foreach ($columns as $column) {
                self::assertSame(BinaryKeyCollationSubscriber::COLLATION, $args->getSchema()->getTable($table)->getColumn($column)->getCollation(), $table . '.' . $column);
            }
            self::assertNull($args->getSchema()->getTable($table)->getColumn('other')->getCollation());
        }

        $sqlite = $this->schemaArgs(new SQLitePlatform(), 'workflow_definition', ['tenant_key']);
        $schema = $sqlite->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($sqlite);
        self::assertSame($schema, $sqlite->getSchema());

        $other  = $this->schemaArgs(new MySQLPlatform(), 'workflow_place', ['name']);
        $schema = $other->getSchema();
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($other);
        self::assertSame($schema, $other->getSchema());

        $partial = $this->schemaArgs(new MySQLPlatform(), 'workflow_subject_pin', ['tenant_key']);
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($partial);
        self::assertSame(BinaryKeyCollationSubscriber::COLLATION, $partial->getSchema()->getTable('workflow_subject_pin')->getColumn('tenant_key')->getCollation());

        $this->expectException(RuntimeException::class);
        (new BinaryKeyCollationSubscriber())->postGenerateSchema($this->schemaArgs(null, 'workflow_definition', ['tenant_key']));
    }

    #[Test]
    public function anUnreadableConcurrentPinIsNeverReplacedByTheProposedVersion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('isTransactionActive')->willReturn(false);
        $sql = [];
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $query) use (&$sql): array {
            $sql[] = $query;

            return [];
        });
        $connection->method('insert')->willThrowException($this->uniqueViolation());

        try {
            (new SubjectPinStore($this->repository($connection)))->pin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), $this->definitionWithId(9));
            self::fail('A snapshot read cannot see the winner: retry.');
        } catch (WorkflowPinConflictException) {
        }
        self::assertStringEndsWith('LOCK IN SHARE MODE', $sql[1] ?? '', 'the winner is read with a locking read');
    }

    #[Test]
    public function aPinCommittedBetweenTheCountAndTheDeleteBlocksTheDelete(): void
    {
        $definition = $this->definitionWithId(5);
        $definition->publish();
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findVersions')->willReturn([$definition]);
        $repository->method('flush')->willThrowException(new ForeignKeyConstraintViolationException($this->driverException(), null));

        try {
            (new WorkflowVersioning($repository))->delete($definition);
            self::fail('The RESTRICT foreign key refuses the delete.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(1, $exception->pinnedSubjects);
            self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function auditBoundariesFollowTheOutermostUnit(): void
    {
        $seen   = [];
        $events = new EventDispatcher();
        $events->addListener(stdClass::class, static function (object $event) use (&$seen): void {
            $seen[] = $event;
        });
        $logger     = new RecordingLogger();
        $boundaries = new WorkflowUnitOfWorkSubscriber();
        $dispatcher = new AfterCommitDispatcher($events, $logger, $boundaries);
        $outer      = $this->consoleEvent();
        $nested     = $this->consoleEvent();
        $connection = new stdClass();

        $boundaries->onConsoleCommand($outer);
        $dispatcher->transactionStarted($connection);
        $boundaries->onConsoleCommand($nested);
        $dispatcher->record($event = new stdClass());
        $boundaries->onConsoleTerminateBookkeeping($nested);
        $dispatcher->onConsoleBoundary($nested);
        $dispatcher->onRequestBoundary();
        self::assertSame(1, $dispatcher->countPending(), 'a nested command or request end keeps the outer transaction\'s event');
        $dispatcher->transactionEnded($connection, true);
        $boundaries->onConsoleTerminateBookkeeping($outer);
        $dispatcher->onConsoleBoundary($outer);
        self::assertSame([$event], $seen);

        // A unit whose end was lost: its leftovers never reach the next main request.
        $dispatcher->record(new stdClass());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $dispatcher->onMainRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $dispatcher->countPending());
        $dispatcher->onMainRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $dispatcher->countPending());
        self::assertStringContainsString('never ended', $logger->records[0]['message'] ?? '');
        $dispatcher->onMainRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $dispatcher->onRequestBoundary();
        (new AfterCommitDispatcher($events))->onConsoleBoundary($outer);
    }

    #[Test]
    public function theAuditMiddlewareObservesOnlyTheDefinitionsAndDefaultConnections(): void
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowEntityManager::class);
        $container->register(PrivateEntityManagerMiddleware::class)->addTag('doctrine.middleware');
        $container->register(AuditMiddleware::class)->addTag('doctrine.middleware');
        $container->setParameter(PrivateEntityManagerPass::ENTITY_MANAGER_PARAMETER, 'workflow');
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setParameter('doctrine.entity_managers', ['default' => 'x', 'workflow' => 'x', 'reports' => 'x']);
        $container->setDefinition('doctrine.orm.workflow_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.wf_connection')]));
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));

        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'wf'], ['connection' => 'app']], $container->getDefinition(AuditMiddleware::class)->getTag('doctrine.middleware'));

        $opted = new ContainerBuilder();
        $opted->register(AuditMiddleware::class)->addTag('doctrine.middleware');
        (new PrivateEntityManagerPass())->process($opted);
        self::assertSame([['connection' => 'default']], $opted->getDefinition(AuditMiddleware::class)->getTag('doctrine.middleware'), 'private manager off: the default connection');
    }

    #[Test]
    public function prefixVariablesNeverOutliveTheirRequest(): void
    {
        $context = new RequestContext();
        $router  = $this->createMock(RequestContextAwareInterface::class);
        $router->method('getContext')->willReturn($context);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $subscriber = new WorkflowUiRouteContextSubscriber($router);

        $workflow = new Request();
        $workflow->attributes->set('_route', 'nowo_workflow_definition_index');
        $workflow->attributes->set('_route_params', ['uuid' => 'org-a']);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $workflow, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('org-a', $context->getParameter('uuid'));

        // The request never finished (fatal error in a worker): the next main request removes it.
        $other = new Request();
        $other->attributes->set('_route', 'app_home');
        $subscriber->onKernelRequest(new RequestEvent($kernel, $other, HttpKernelInterface::SUB_REQUEST));
        self::assertSame('org-a', $context->getParameter('uuid'), 'a sub-request keeps its main request\'s variables');
        $subscriber->onKernelRequest(new RequestEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($context->getParameter('uuid'));

        $subscriber->onKernelRequest(new RequestEvent($kernel, $workflow, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->reset();
        self::assertNull($context->getParameter('uuid'), 'kernel.reset');
        $subscriber->reset();
    }

    #[Test]
    public function aFailingPermissionPortBlocksTheTransition(): void
    {
        $checker = $this->createMock(WorkflowTransitionPermissionCheckerInterface::class);
        $checker->method('isGranted')->willThrowException(new RuntimeException('directory down'));
        $logger     = new RecordingLogger();
        $transition = new Transition('approve', 'a', 'b');
        /** @var SplObjectStorage<Transition, array> $metadata */ // @phpstan-ignore missingType.iterableValue
        $metadata = new SplObjectStorage();
        $metadata->offsetSet($transition, [DatabaseMetadataStore::GUARD_PERMISSION => 'leave.approve']);
        $workflow = new StateMachine(new WorkflowShape(['a', 'b'], [$transition], 'a', new InMemoryMetadataStore([DatabaseMetadataStore::BUNDLE_MARKER => true], [], $metadata)), null, null, 'leave');
        $event    = new GuardEvent(new stdClass(), new Marking(['a' => 1]), $transition, $workflow);

        (new TransitionGuardSubscriber(new TenancyContext(), $checker, null, $logger))->onGuard($event);

        self::assertTrue($event->isBlocked());
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
        self::assertArrayHasKey('error', $logger->records[0]['context']);
        self::assertNull($logger->records[0]['context']['error'], 'no message of a host exception is logged');
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $driver = new class('duplicate') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        return new UniqueConstraintViolationException($driver, null);
    }

    private function driverException(): DriverException
    {
        return new class('constraint') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };
    }

    private function consoleEvent(): object
    {
        return new class(new stdClass(), new stdClass()) {
            public function __construct(private readonly object $command, private readonly object $input)
            {
            }

            public function getCommand(): object
            {
                return $this->command;
            }

            public function getInput(): object
            {
                return $this->input;
            }
        };
    }

    /**
     * @param list<string> $columns
     */
    /**
     * The schema tool's `postGenerateSchema` event for one generated table on a manager with that platform (null: the
     * platform cannot be read).
     *
     * @param list<string> $columns
     */
    private function schemaArgs(?object $platform, string $tableName, array $columns): GenerateSchemaEventArgs
    {
        $table = new Table($tableName);
        foreach ($columns as $column) {
            $table->addColumn($column, 'string', ['length' => 128]);
        }
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $manager = $this->manager($platform);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $manager->method('getClassMetadata')->willReturnCallback(static function (string $class): ClassMetadata {
            /** @var class-string $class */
            $metadata = new ClassMetadata($class);
            $metadata->setPrimaryTable(['name' => $class === WorkflowDefinition::class ? 'workflow_definition' : 'workflow_subject_pin']);

            return $metadata;
        });

        return new GenerateSchemaEventArgs($manager, new Schema([$table]));
    }

    private function repository(Connection $connection): WorkflowDefinitionRepository
    {
        $metadata = $this->metadata(WorkflowSubjectPin::class, ['id', 'tenantKey', 'workflowSlug', 'subjectRef', 'createdAt']);
        $metadata->setTableName('workflow_subject_pin');
        $metadata->mapManyToOne(['fieldName' => 'definition', 'targetEntity' => WorkflowDefinition::class, 'joinColumns' => [['name' => 'definition_id', 'referencedColumnName' => 'id']]]);
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('connection')->willReturn($connection);
        $repository->method('bundleMetadata')->willReturn($metadata);

        return $repository;
    }

    private function definitionWithId(int $id): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, $id);

        return $definition;
    }

    /**
     * @param class-string $class
     * @param list<string> $fields
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, array $fields): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        foreach ($fields as $field) {
            $metadata->fieldMappings[$field] = FieldMapping::fromMappingArray(['fieldName' => $field, 'columnName' => $field, 'type' => 'string']);
            $metadata->columnNames[$field]   = $metadata->fieldMappings[$field]->columnName;
        }

        return $metadata;
    }

    private function manager(?object $platform): EntityManagerInterface&MockObject
    {
        $connection = $this->createMock(Connection::class);
        if ($platform === null) {
            $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('no database'));
        } else {
            $connection->method('getDatabasePlatform')->willReturn($platform);
        }
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }
}
