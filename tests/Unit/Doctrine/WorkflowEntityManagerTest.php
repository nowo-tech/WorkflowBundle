<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\WorkflowBundle\Tests\Support\TenantFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
