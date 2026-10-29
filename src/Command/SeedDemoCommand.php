<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Command;

use Nowo\WorkflowBundle\Service\DemoSeedService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Console command that seeds the demo workflow definitions (nowo:workflow:seed-demo).
 */
#[AsCommand(
    name: 'nowo:workflow:seed-demo',
    description: 'Seed demo workflow definitions (orders, change requests, documents, expenses, POs)',
)]
final class SeedDemoCommand extends Command
{
    /**
     * Creates the command with the demo seeding service and an optional PSR-3 logger.
     */
    public function __construct(
        private readonly DemoSeedService $demoSeedService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Remove existing definitions before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $fresh   = (bool) $input->getOption('fresh');
        $context = ['bundle' => 'NowoWorkflowBundle', 'action' => 'seed_demo', 'fresh' => $fresh];
        $this->logger->info('Workflow demo seeding started.', $context);

        try {
            $this->demoSeedService->seed($fresh);
        } catch (Throwable $exception) {
            $this->logger->error('Workflow demo seeding failed.', $context + ['exception' => $exception]);

            throw $exception;
        }

        $this->logger->info('Workflow demo seeding finished.', $context);
        $io->success('Demo workflow definitions seeded.');

        return Command::SUCCESS;
    }
}
