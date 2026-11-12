<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Command;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowShapeValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Lists the stored definitions (every tenant and version) whose transitions FrameworkBundle would refuse
 * (nowo:workflow:lint, since 1.15.2): a transition without source or target place, or a state machine transition with
 * several target places. 1.15.x still runs them (with a deprecation); 1.16.0 refuses to build them. Exit code 1 when
 * one is found.
 */
#[AsCommand(
    name: 'nowo:workflow:lint',
    description: 'List stored workflow definitions with transitions FrameworkBundle would refuse',
)]
final class LintCommand extends Command
{
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $rows = [];
        /** @var list<WorkflowDefinition> $definitions */
        $definitions = $this->repository->findBy([], ['slug' => 'ASC', 'tenantKey' => 'ASC', 'version' => 'ASC']);
        foreach ($definitions as $definition) {
            foreach (WorkflowShapeValidator::violations($definition) as $violation) {
                $rows[] = [(string) $definition->getId(), $definition->getSlug(), $definition->getTenantKey() === '' ? '(template)' : $definition->getTenantKey(), (string) $definition->getVersion(), $definition->getStatus()->value, $violation];
            }
        }
        // The private entity manager keeps nothing between commands (worker boundary); nothing was changed.

        if ($rows === []) {
            $io->success(sprintf('%d definition(s) checked: every transition is valid.', count($definitions)));

            return Command::SUCCESS;
        }

        $io->table(['id', 'slug', 'tenant', 'version', 'status', 'problem'], $rows);
        $io->error(sprintf('%d invalid transition(s). Fix them (one transition per target place on a state machine) before upgrading to 1.16.0, which refuses to build them.', count($rows)));

        return Command::FAILURE;
    }
}
