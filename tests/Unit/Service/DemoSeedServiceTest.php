<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use PHPUnit\Framework\TestCase;

final class DemoSeedServiceTest extends TestCase
{
    public function testSeedPersistsDefinitionsWhenEmpty(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist')->with(self::isInstanceOf(WorkflowDefinition::class));
        $em->expects(self::once())->method('flush');

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        (new DemoSeedService($em, $repository, $registry))->seed();
    }

    public function testFreshRemovesExistingDefinitions(): void
    {
        $existing = new WorkflowDefinition('Old', 'old', 'draft', 'App\\Entity\\X');

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findAll')->willReturn([$existing]);
        $repository->method('findOneBySlug')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($existing);
        $em->expects(self::exactly(2))->method('flush');

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        (new DemoSeedService($em, $repository, $registry))->seed(true);
    }

    public function testSkipsExistingSlug(): void
    {
        $existing = new WorkflowDefinition('Order', 'order_approval_default', 'draft', 'App\\Entity\\DemoOrder');

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturnCallback(
            static fn (string $slug): ?WorkflowDefinition => $slug === 'order_approval_default' ? $existing : null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        (new DemoSeedService($em, $repository, $registry))->seed();
    }

    public function testSeedDoesNothingWhenAllDefinitionsExist(): void
    {
        $existing = new WorkflowDefinition('Existing', 'existing', 'draft', 'App\\Entity\\X');

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $registry = new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder());

        (new DemoSeedService($em, $repository, $registry))->seed();
    }
}
