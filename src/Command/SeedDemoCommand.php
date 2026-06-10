<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Command;

use Nowo\WorkflowBundle\Service\DemoSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nowo:workflow:seed-demo',
    description: 'Seed demo workflow definitions (orders, change requests, documents, expenses, POs)',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly DemoSeedService $demoSeedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Remove existing definitions before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->demoSeedService->seed((bool) $input->getOption('fresh'));
        $io->success('Demo workflow definitions seeded.');

        return Command::SUCCESS;
    }
}
