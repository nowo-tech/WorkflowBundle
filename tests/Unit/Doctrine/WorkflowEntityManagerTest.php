<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use Nowo\WorkflowBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\WorkflowBundle\Tests\Support\TenantFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;

use function dirname;

/**
 * 1.14 private entity manager (REQ-ARCH-001 rule 6, REQ-RUNTIME-002 rule 4): never the application's manager, kept
 * while it has pending work after a rollback, replaced when closed, mirrors the source SQL filters.
 */
final class WorkflowEntityManagerTest extends TestCase
{
    #[Test]
    public function aStaleManagerWithPendingWorkIsKeptUntilFlushed(): void
    {
        [$doctrine, $private] = $this->synced();
        $live                 = $private->live();
        $definition           = $this->definition('a');
        $live->persist($definition);

        $private->connectionRolledBack();
        self::assertSame($live, $private->live(), 'pending inserts survive a rollback signal');
        self::assertTrue($private->isStale());

        $private->flush();
        $private->connectionRolledBack();
        self::assertNotSame($live, $private->live(), 'no pending work: replaced');
        $host = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertSame(0, $host->getUnitOfWork()->size(), 'the application manager holds nothing');
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_definition'));
    }

    #[Test]
    public function anEditedManagedRowCountsAsPendingWork(): void
    {
        [, $private] = $this->synced();
        $private->persist($this->definition('b'));
        $private->flush();
        $loaded = $private->createQueryBuilder()->select('d')->from(WorkflowDefinition::class, 'd')->where('d.slug = :s')->setParameter('s', 'b')->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(WorkflowDefinition::class, $loaded);
        $loaded->setName('Renamed');
        $live = $private->live();

        $private->connectionRolledBack();
        self::assertSame($live, $private->live());
    }

    #[Test]
    public function theConfiguredSourceManagerIsUsedAndAnotherConnectionReplacesTheManager(): void
    {
        $entities = ['Nowo\\WorkflowBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => [], 'workflow' => $entities]);
        $private  = new WorkflowEntityManager($doctrine, 'workflow');

        self::assertSame($doctrine->getManager('workflow'), $private->source());
        self::assertSame($doctrine->getConnection('workflow'), $private->live()->getConnection());
        self::assertNotSame($doctrine->getManager('workflow'), $private->live());
    }

    #[Test]
    public function aSourceThatIsNotAnOrmManagerFails(): void
    {
        $this->expectException(LogicException::class);
        (new WorkflowEntityManager(new SqliteManagerRegistry(['default' => []])))->source();
    }

    #[Test]
    public function theSourceSqlFiltersAreMirroredWithTheirParameters(): void
    {
        [$doctrine, $private] = $this->synced();
        $source               = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $source);
        $source->getConfiguration()->addFilter('tenant', TenantFilter::class);
        $private->persist($this->definition('one'));
        $private->persist($this->definition('two'));
        $private->flush();
        $private->clear();

        $source->getFilters()->enable('tenant')->setParameter('slug', 'one');
        self::assertSame(['two'], $this->slugs($private));

        $source->getFilters()->getFilter('tenant')->setParameter('slug', 'two');
        self::assertSame(['one'], $this->slugs($private), 'a changed parameter is copied');

        $source->getFilters()->disable('tenant');
        self::assertSame(['one', 'two'], $this->slugs($private), 'a filter the source disabled is disabled');

        $source->getFilters()->enable('tenant')->setParameterList('slug', ['x', 'y']);
        $private->live();
        self::assertTrue($private->live()->getFilters()->isEnabled('tenant'));
    }

    #[Test]
    public function aBoundaryThatDropsPendingChangesLogsThemPerClass(): void
    {
        $logger     = new RecordingLogger();
        [$doctrine] = $this->synced();
        $private    = new WorkflowEntityManager($doctrine, null, $logger);
        $private->persist($this->definition('kept'));
        $private->flush();
        $loaded = $private->createQueryBuilder()->select('d')->from(WorkflowDefinition::class, 'd')->getQuery()->getSingleResult();
        self::assertInstanceOf(WorkflowDefinition::class, $loaded);
        $loaded->setName('Edited, never saved');
        $private->persist($this->definition('new'));

        $private->reset();

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(2, $logger->records[0]['context']['count']);
        self::assertSame([WorkflowDefinition::class => 2], $logger->records[0]['context']['changes']);
        self::assertFalse($private->isCreated());

        $private->live();
        $private->reset();
        $closed = $private->live();
        $closed->close();
        $private->reset();
        (new WorkflowEntityManager($doctrine))->reset();
        self::assertCount(1, $logger->records, 'nothing pending, a closed manager or no logger: no warning');
    }

    #[Test]
    public function aBoundaryThatCannotInspectThePendingChangesStillDropsTheManager(): void
    {
        $logger     = new RecordingLogger();
        [$doctrine] = $this->synced();
        $private    = new WorkflowEntityManager($doctrine, null, $logger);
        // A unit of work whose change sets cannot be computed (invalid association, detached or half-built entity).
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        $failing->expects(self::once())->method('clear');
        (new ReflectionProperty(WorkflowEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated(), 'NF2-2: dropped although the inspection failed');
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);

        $private->persist($this->definition('next'));
        $private->flush();
        $private->reset();
        self::assertCount(1, $logger->records, 'the next unit of work and boundary work');
    }

    #[Test]
    public function aFailingLoggerNeverBreaksTheBoundary(): void
    {
        [$doctrine] = $this->synced();
        $logger     = new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $private = new WorkflowEntityManager($doctrine, null, $logger);
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        (new ReflectionProperty(WorkflowEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated());
    }

    #[Test]
    public function aSourceManagerThatDoesNotMapTheEntitiesFails(): void
    {
        $entities = ['Nowo\\WorkflowBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => [], 'workflow' => $entities]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"default"');
        (new WorkflowEntityManager($doctrine, 'default'))->source();
    }

    #[Test]
    public function listFilterParametersKeepTheirType(): void
    {
        [$doctrine, $private] = $this->synced();
        $source               = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $source);
        $source->getConfiguration()->addFilter('tenant', TenantFilter::class);
        $source->getFilters()->enable('tenant')->setParameterList('ids', [1, 2], 'integer')->setParameter('slug', 'x');

        $copy = $private->live()->getFilters()->getFilter('tenant');
        self::assertSame((string) $source->getFilters()->getFilter('tenant'), (string) $copy, 'same values, list flag and types');
    }

    /**
     * @return array{SqliteManagerRegistry, WorkflowEntityManager}
     */
    private function synced(): array
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\WorkflowBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]);
        $source   = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $source);
        (new SchemaTool($source))->createSchema($source->getMetadataFactory()->getAllMetadata());

        return [$doctrine, new WorkflowEntityManager($doctrine)];
    }

    private function definition(string $slug): WorkflowDefinition
    {
        return new WorkflowDefinition('Flow ' . $slug, $slug, 'draft', 'App\\Entity\\Order', WorkflowType::StateMachine);
    }

    /**
     * @return list<string>
     */
    private function slugs(WorkflowEntityManager $private): array
    {
        $private->clear();
        $slugs = [];
        /** @var list<WorkflowDefinition> $definitions */
        $definitions = $private->createQueryBuilder()->select('d')->from(WorkflowDefinition::class, 'd')->orderBy('d.slug')->getQuery()->getResult();
        foreach ($definitions as $definition) {
            $slugs[] = $definition->getSlug();
        }

        return $slugs;
    }
}
