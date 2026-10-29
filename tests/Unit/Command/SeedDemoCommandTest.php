<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Command\SeedDemoCommand;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\DemoSeedService;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    public function testExecuteLogsStartAndSuccess(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findAll')->willReturn([]);
        $repository->method('findOneBySlug')->willReturn(null);

        $service = new DemoSeedService(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
        );
        $logger = new RecordingLogger();

        (new CommandTester(new SeedDemoCommand($service, $logger)))->execute(['--fresh' => true]);

        self::assertSame(['info: Workflow demo seeding started.', 'info: Workflow demo seeding finished.'], $logger->lines());
        self::assertSame(['bundle' => 'NowoWorkflowBundle', 'action' => 'seed_demo', 'fresh' => true], $logger->records[1]['context']);
    }

    public function testExecuteLogsAndRethrowsFailures(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findAll')->willThrowException(new RuntimeException('database unavailable'));

        $service = new DemoSeedService(
            $this->createMock(EntityManagerInterface::class),
            $repository,
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
        );
        $logger = new RecordingLogger();
        $tester = new CommandTester(new SeedDemoCommand($service, $logger));

        try {
            $tester->execute(['--fresh' => true]);
            self::fail('The seeding failure must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('database unavailable', $exception->getMessage());
        }

        self::assertSame(['info: Workflow demo seeding started.', 'error: Workflow demo seeding failed.'], $logger->lines());
        self::assertInstanceOf(RuntimeException::class, $logger->records[1]['context']['exception'] ?? null);
    }
}
