<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Service\SchemaSyncService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function sprintf;

/**
 * Console command that creates or updates the Workflow Bundle tables (nowo:workflow:sync-schema).
 */
#[AsCommand(
    name: 'nowo:workflow:sync-schema',
    description: 'Create or update database tables for Workflow Bundle entities',
)]
final class SyncSchemaCommand extends Command
{
    /**
     * Creates the command with the Doctrine registry, the configured connection name and an optional PSR-3 logger.
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        #[Autowire(param: 'nowo_workflow.connection')]
        private readonly string $connectionName,
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $context = ['bundle' => 'NowoWorkflowBundle', 'action' => 'sync_schema', 'connection' => $this->connectionName];
        $this->logger->info('Workflow schema sync started.', $context);

        try {
            $syncService                                     = new SchemaSyncService($manager);
            $statements                                      = $syncService->getSyncSchemaSql();
            ['executed' => $executed, 'skipped' => $skipped] = $syncService->executeStatements($manager->getConnection(), $statements);
        } catch (Throwable $exception) {
            $this->logger->error('Workflow schema sync failed.', $context + ['exception' => $exception]);

            throw $exception;
        }

        $this->logger->info('Workflow schema sync finished.', $context + ['executed' => $executed, 'skipped' => $skipped]);

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
