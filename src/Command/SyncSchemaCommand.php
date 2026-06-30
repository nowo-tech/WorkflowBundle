<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Service\SchemaSyncService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

#[AsCommand(
    name: 'nowo:workflow:sync-schema',
    description: 'Create or update database tables for Workflow Bundle entities',
)]
final class SyncSchemaCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        #[Autowire('%nowo_workflow.connection%')]
        private readonly string $connectionName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $manager = $this->registry->getManager($this->connectionName);
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException(sprintf('Connection "%s" is not an ORM entity manager.', $this->connectionName));
        }

        $syncService                                     = new SchemaSyncService($manager);
        $connection                                      = $manager->getConnection();
        $statements                                      = $syncService->getSyncSchemaSql();
        ['executed' => $executed, 'skipped' => $skipped] = $syncService->executeStatements($connection, $statements);

        if ($executed === 0 && $skipped === 0) {
            $io->success('Database schema is already up to date.');

            return Command::SUCCESS;
        }

        $message = sprintf('Executed %d SQL statement(s).', $executed);
        if ($skipped > 0) {
            $message .= sprintf(' Skipped %d already applied.', $skipped);
        }

        $io->success($message);

        return Command::SUCCESS;
    }
}
