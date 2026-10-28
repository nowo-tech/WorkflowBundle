<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

final class WorkflowDefinitionRepositoryIntegrationTest extends TestCase
{
    private WorkflowDefinitionRepository $repository;

    protected function setUp(): void
    {
        $entityManager    = IntegrationEntityManagerFactory::createSyncedInMemory();
        $this->repository = new WorkflowDefinitionRepository(new TestManagerRegistry($entityManager));

        $enabled = new WorkflowDefinition('Enabled order', 'enabled_order', 'draft', 'App\\Entity\\Order');
        $enabled->addPlace(new WorkflowPlace('draft'));
        $enabled->setPriority(10);

        $disabled = new WorkflowDefinition('Disabled doc', 'disabled_doc', 'draft', 'App\\Entity\\Document');
        $disabled->addPlace(new WorkflowPlace('draft'));
        $disabled->setEnabled(false);

        $otherSubject = new WorkflowDefinition('Invoice', 'invoice_flow', 'draft', 'App\\Entity\\Invoice');
        $otherSubject->addPlace(new WorkflowPlace('draft'));

        $entityManager->persist($enabled);
        $entityManager->persist($disabled);
        $entityManager->persist($otherSubject);
        $entityManager->flush();
    }

    public function testFindOneBySlug(): void
    {
        $definition = $this->repository->findOneBySlug('enabled_order');

        self::assertInstanceOf(WorkflowDefinition::class, $definition);
        self::assertSame('Enabled order', $definition->getName());
    }

    public function testFindAllEnabled(): void
    {
        $definitions = $this->repository->findAllEnabled();

        self::assertCount(2, $definitions);
        self::assertSame('Enabled order', $definitions[0]->getName());
        self::assertSame('Invoice', $definitions[1]->getName());
    }

    public function testCountEnabled(): void
    {
        self::assertSame(2, $this->repository->countEnabled());
    }

    public function testFindEnabledCandidatesFiltersBySubjectClass(): void
    {
        $definitions = $this->repository->findEnabledCandidates('App\\Entity\\Order');

        self::assertCount(1, $definitions);
        self::assertSame('enabled_order', $definitions[0]->getSlug());
    }

    public function testFindEnabledCandidatesAcceptsClassHierarchy(): void
    {
        $definitions = $this->repository->findEnabledCandidates(['App\\Entity\\OrderChild', 'App\\Entity\\Order']);

        self::assertCount(1, $definitions);
        self::assertSame('enabled_order', $definitions[0]->getSlug());
    }

    public function testFindEnabledCandidatesWithoutSubjectClass(): void
    {
        self::assertCount(2, $this->repository->findEnabledCandidates(null));
    }

    public function testPaginateByNameRespectsPageSize(): void
    {
        $page = $this->repository->paginateByName(1, 2);

        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['page_size']);
        self::assertSame(2, $page['pages']);
        self::assertSame(1, $page['page']);
        self::assertCount(2, $page['items']);
        self::assertSame('Disabled doc', $page['items'][0]->getName());
        self::assertSame('Enabled order', $page['items'][1]->getName());

        $page2 = $this->repository->paginateByName(2, 2);
        self::assertCount(1, $page2['items']);
        self::assertSame('Invoice', $page2['items'][0]->getName());
    }

    public function testPaginateByNameCountsDefinitionsNotJoinedRows(): void
    {
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory();
        $repository    = new WorkflowDefinitionRepository(new TestManagerRegistry($entityManager));
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $definition = new WorkflowDefinition($name, strtolower($name), 'draft', 'App\\Entity\\Order');
            foreach (['draft', 'review', 'done'] as $place) {
                $definition->addPlace(new WorkflowPlace($place));
            }
            $entityManager->persist($definition);
        }
        $entityManager->flush();
        $entityManager->clear();

        $page = $repository->paginateByName(1, 2);

        self::assertSame(['Alpha', 'Beta'], array_map(static fn (WorkflowDefinition $d): string => $d->getName(), $page['items']));
        self::assertCount(3, $page['items'][0]->getPlaces());
    }

    public function testPaginateByNameLoadsCollectionsWithoutCartesianJoinOrLazyLoads(): void
    {
        $queries       = new DebugDataHolder();
        $entityManager = IntegrationEntityManagerFactory::createSyncedInMemory(null, $queries);
        $repository    = new WorkflowDefinitionRepository(new TestManagerRegistry($entityManager));
        foreach (['Alpha', 'Beta', 'Gamma'] as $i => $name) {
            $definition = new WorkflowDefinition($name, strtolower($name), 'draft', 'App\\Entity\\Order');
            foreach (['draft', 'review', 'done'] as $place) {
                $definition->addPlace(new WorkflowPlace($place));
            }
            for ($t = 0; $t <= $i; ++$t) {
                $definition->addTransition(new WorkflowTransition('t' . $t, ['draft'], ['done']));
            }
            $definition->addMatchRule(new WorkflowMatchRule('type', $name));
            $entityManager->persist($definition);
        }
        $entityManager->flush();
        $entityManager->clear();

        $queries->reset();
        $page   = $repository->paginateByName(1, 2);
        $counts = array_map(static fn (WorkflowDefinition $d): array => [
            $d->getName(), $d->getPlaces()->count(), $d->getTransitions()->count(), $d->getMatchRules()->count(),
        ], $page['items']);

        self::assertSame([['Alpha', 3, 1, 1], ['Beta', 3, 2, 1]], $counts);
        // COUNT + page + one fetch-join per collection; reading the collections afterwards costs nothing.
        self::assertCount(5, $queries->getData()['default'] ?? []);
    }

    public function testPaginateByNameOnAnEmptyTable(): void
    {
        $repository = new WorkflowDefinitionRepository(new TestManagerRegistry(IntegrationEntityManagerFactory::createSyncedInMemory()));

        $page = $repository->paginateByName(1, 10);

        self::assertSame([], $page['items']);
        self::assertSame(0, $page['total']);
    }

    public function testPaginateByNameWithZeroPageSizeReturnsAll(): void
    {
        $page = $this->repository->paginateByName(1, 0);

        self::assertSame(0, $page['page_size']);
        self::assertSame(1, $page['pages']);
        self::assertCount(3, $page['items']);
    }
}
