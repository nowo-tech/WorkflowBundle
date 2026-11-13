<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Collation;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;
use Nowo\WorkflowBundle\Collation\WorkflowExactKeyColumns;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Doctrine\TablePrefixSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;

use function dirname;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 gate for this bundle's own CI, against a real MySQL / MariaDB server
 * (`NOWO_WORKFLOW_TEST_MYSQL_URL`, set by the `mysql-schema` job; skipped without it).
 *
 * The schema is created from the bundle's own mapping with the real listeners, under a **non-default table prefix**,
 * so the run proves the two things a host-side aggregate run depends on: that the collation the schema actually gets
 * is binary NO PAD, and that the declaration names the table the host really has rather than a literal.
 *
 * A key column added to the mapping and left on the server default fails here, in this bundle's pipeline, before any
 * host installs it.
 */
final class WorkflowExactKeyColumnsTest extends ExactKeyColumnsTestCase
{
    private const PREFIX = 'colcheck_';

    private ?EntityManagerInterface $manager = null;

    protected function tearDown(): void
    {
        if ($this->manager instanceof EntityManagerInterface) {
            (new SchemaTool($this->manager))->dropSchema($this->metadata($this->manager));
            $this->manager->getConnection()->close();
            $this->manager = null;
        }

        parent::tearDown();
    }

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'NOWO_WORKFLOW_TEST_MYSQL_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new WorkflowExactKeyColumns(new TableNamePrefixer(self::PREFIX))];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $manager = new EntityManager($connection, $config);
        $manager->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber(new TableNamePrefixer(self::PREFIX)));
        $manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new BinaryKeyCollationSubscriber(new TestManagerRegistry($manager)));
        $this->manager = $manager;

        $tool = new SchemaTool($manager);
        $tool->dropSchema($this->metadata($manager));
        $tool->createSchema($this->metadata($manager));
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function metadata(EntityManagerInterface $manager): array
    {
        return [$manager->getClassMetadata(WorkflowDefinition::class), $manager->getClassMetadata(WorkflowSubjectPin::class)];
    }
}
