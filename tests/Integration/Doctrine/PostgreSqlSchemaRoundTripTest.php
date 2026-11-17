<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function getenv;
use function is_string;

use const PHP_VERSION_ID;

/**
 * REQ-TEST-005, against a real PostgreSQL server (`NOWO_WORKFLOW_TEST_PGSQL_URL`, e.g.
 * `pdo-pgsql://postgres@postgres:5432/workflow`; skipped without it): the bundle schema is created from its own mapping,
 * the diff is empty afterwards and a definition with JSON metadata round-trips.
 */
#[Group('database')]
final class PostgreSqlSchemaRoundTripTest extends TestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $url = getenv('NOWO_WORKFLOW_TEST_PGSQL_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('NOWO_WORKFLOW_TEST_PGSQL_URL is not set (PostgreSQL round trip).');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params        = (new DsnParser(['pgsql' => 'pdo_pgsql', 'pdo-pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $this->manager = new EntityManager(DriverManager::getConnection($params, $config), $config);

        $tool = new SchemaTool($this->manager);
        $tool->dropSchema($this->metadata());
        $tool->createSchema($this->metadata());
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            (new SchemaTool($this->manager))->dropSchema($this->metadata());
            $this->manager->getConnection()->close();
        }
    }

    #[Test]
    public function theDiffIsEmptyAfterTheSchemaIsCreated(): void
    {
        self::assertSame([], (new SchemaTool($this->manager))->getUpdateSchemaSql($this->metadata()));
    }

    #[Test]
    public function aDefinitionRoundTripsWithItsJsonMetadata(): void
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        $this->manager->persist($definition);
        $this->manager->flush();
        $id = $definition->getId();
        self::assertNotNull($id);
        $this->manager->clear();

        $loaded = $this->manager->find(WorkflowDefinition::class, $id);

        self::assertInstanceOf(WorkflowDefinition::class, $loaded);
        self::assertSame('leave', $loaded->getSlug());
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_definition'));
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function metadata(): array
    {
        return $this->manager->getMetadataFactory()->getAllMetadata();
    }
}
