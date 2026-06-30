<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\TestCase;

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

    public function testFindEnabledCandidatesFiltersBySubjectClass(): void
    {
        $definitions = $this->repository->findEnabledCandidates('App\\Entity\\Order');

        self::assertCount(1, $definitions);
        self::assertSame('enabled_order', $definitions[0]->getSlug());
    }

    public function testFindEnabledCandidatesWithoutSubjectClass(): void
    {
        self::assertCount(2, $this->repository->findEnabledCandidates(null));
    }
}
