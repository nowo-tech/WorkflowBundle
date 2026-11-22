<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\StaleReadDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * REQ-RUNTIME-003 for WorkflowDefinitionCatalogInterface::installTemplate(): two installs of one template for one
 * tenant leave exactly one definition, whichever way the race goes. The using class provides `connect()`, a new
 * connection (and entity manager) on the shared database.
 */
trait ConcurrentInstallScenarios
{
    private const TEMPLATE = 'race_template';

    abstract protected function connect(): EntityManagerInterface;

    #[Test]
    public function aSecondInstallIsANoOpAndKeepsOneDefinition(): void
    {
        [$a, $tool, $metadata] = $this->prepare();

        try {
            $tenant = new WorkflowTenant('org-a');
            $first  = $this->catalog($a)->installTemplate(self::TEMPLATE, $tenant);
            $again  = $this->catalog($a)->installTemplate(self::TEMPLATE, $tenant);

            self::assertTrue($first->created);
            self::assertFalse($again->created);
            self::assertSame($first->definition->ref->id, $again->definition->ref->id);
            self::assertSame(1, $this->tenantRows($a, 'org-a'));
        } finally {
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
        }
    }

    #[Test]
    public function aLoserThatReadNothingBeforeTheWinnerCommittedGetsTheWinnersDefinition(): void
    {
        [$a, $tool, $metadata] = $this->prepare();
        $b                     = $this->connect();

        try {
            $tenant = new WorkflowTenant('org-a');
            // B reads "nothing" first, A installs, then B writes: the unique (tenant_key, slug, version) key refuses B.
            $stale = new StaleReadDefinitionRepository(new TestManagerRegistry($b));
            $loser = new WorkflowDefinitionCatalog(new WorkflowVersioning($stale), $stale);

            $winner = $this->catalog($a)->installTemplate(self::TEMPLATE, $tenant);
            $result = $loser->installTemplate(self::TEMPLATE, $tenant);

            self::assertTrue($winner->created);
            self::assertFalse($result->created, 'the loser reports the existing definition, not a second one');
            self::assertSame($winner->definition->ref->id, $result->definition->ref->id);
            self::assertSame(1, $this->tenantRows($a, 'org-a'), 'exactly one definition for the tenant');
        } finally {
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
            $b->getConnection()->close();
        }
    }

    #[Test]
    public function anInstallForAnotherTenantIsNotBlockedByTheFirst(): void
    {
        [$a, $tool, $metadata] = $this->prepare();
        $b                     = $this->connect();

        try {
            $stale = new StaleReadDefinitionRepository(new TestManagerRegistry($b));
            $other = new WorkflowDefinitionCatalog(new WorkflowVersioning($stale), $stale);

            self::assertTrue($this->catalog($a)->installTemplate(self::TEMPLATE, new WorkflowTenant('org-a'))->created);
            self::assertTrue($other->installTemplate(self::TEMPLATE, new WorkflowTenant('org-b'))->created);
            self::assertSame(1, $this->tenantRows($a, 'org-a'));
            self::assertSame(1, $this->tenantRows($a, 'org-b'));
        } finally {
            $tool->dropSchema($metadata);
            $a->getConnection()->close();
            $b->getConnection()->close();
        }
    }

    protected function catalog(EntityManagerInterface $manager): WorkflowDefinitionCatalog
    {
        $repository = new StaleReadDefinitionRepository(new TestManagerRegistry($manager));
        $repository->catchUp();

        return new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);
    }

    protected function tenantRows(EntityManagerInterface $manager, string $tenant): int
    {
        return (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_definition WHERE slug = ? AND tenant_key = ?', [self::TEMPLATE, $tenant]);
    }

    /**
     * A fresh schema with the published global template.
     *
     * @return array{0: EntityManagerInterface, 1: SchemaTool, 2: list<ClassMetadata<object>>}
     */
    protected function prepare(): array
    {
        $manager  = $this->connect();
        $tool     = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $template = new WorkflowDefinition('Race template', self::TEMPLATE, 'draft', 'App\\Entity\\Race');
        $template->addPlace(new WorkflowPlace('draft', null, 0));
        $template->addPlace(new WorkflowPlace('review', null, 1));
        $template->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        $template->publish();
        $manager->persist($template);
        $manager->flush();

        return [$manager, $tool, $metadata];
    }
}
