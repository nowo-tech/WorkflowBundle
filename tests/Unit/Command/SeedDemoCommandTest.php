<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Command\SeedDemoCommand;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoCommandTest extends TestCase
{
    public function testExecuteSeedsWithoutFresh(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);

        $em      = $this->createMock(EntityManagerInterface::class);
        $service = new DemoSeedService(
            $em,
            $repository,
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
        );

        $tester = new CommandTester(new SeedDemoCommand($service));
        $code   = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('seeded', $tester->getDisplay());
    }

    public function testExecuteSeedsWithFreshOption(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findAll')->willReturn([]);
        $repository->method('findOneBySlug')->willReturn(null);

        $em      = $this->createMock(EntityManagerInterface::class);
        $service = new DemoSeedService(
            $em,
            $repository,
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
        );

        $tester = new CommandTester(new SeedDemoCommand($service));
        $code   = $tester->execute(['--fresh' => true]);

        self::assertSame(0, $code);
    }
}
