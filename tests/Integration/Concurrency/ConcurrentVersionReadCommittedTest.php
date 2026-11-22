<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Integration\IntegrationEntityManagerFactory;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\LockingSqlMiddleware;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function dirname;
use function is_file;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_VERSION_ID;

/**
 * Since 2.2 (default suite): on MySQL / MariaDB a version-claim unit the bundle opens itself runs at READ COMMITTED
 * (`SET TRANSACTION`, the next transaction only); inside the host's transaction its level applies and nothing is set.
 * A SQLite file reports a MySQL platform ({@see LockingSqlMiddleware}); the real servers run ConcurrentVersion*Test.
 */
final class ConcurrentVersionReadCommittedTest extends TestCase
{
    private const SET = 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED';

    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'wfrc');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function onlyTheBundlesOwnUnitsAreSetToReadCommitted(): void
    {
        $plain = IntegrationEntityManagerFactory::createForParams(RaceHarness::sqliteParams($this->path));
        (new SchemaTool($plain))->createSchema($plain->getMetadataFactory()->getAllMetadata());
        $published = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        $published->addPlace(new WorkflowPlace('a'));
        $published->publish();
        $plain->persist($published);
        $plain->flush();
        $plain->getConnection()->close();

        $locking    = new LockingSqlMiddleware(new MySQL80Platform(), [self::SET => 'SELECT 1', 'SHOW SESSION VARIABLES' => 'SELECT 1, 1 WHERE 0']);
        $manager    = $this->mysqlManager($locking);
        $repository = new WorkflowDefinitionRepository(new TestManagerRegistry($manager));
        $source     = $repository->findCurrent('leave', null);
        self::assertInstanceOf(WorkflowDefinition::class, $source);
        $write = static function (int $version) use ($repository, $source): int {
            $repository->save($source->copyAsDraft(null, $version));

            return $version;
        };

        self::assertSame(2, $repository->insertNextVersion('leave', null, $write));
        self::assertCount(2, $this->sets($locking), 'the counter unit and the claim unit');

        $locking->statements = [];
        $connection          = $manager->getConnection();
        $connection->beginTransaction();
        self::assertSame(3, $repository->insertNextVersion('leave', null, $write));
        $connection->commit();
        self::assertSame([], $this->sets($locking), 'inside the host\'s transaction its level applies');
        $connection->close();
    }

    /**
     * @return list<string>
     */
    private function sets(LockingSqlMiddleware $locking): array
    {
        return array_values(array_filter($locking->statements, static fn (string $sql): bool => str_starts_with($sql, self::SET)));
    }

    private function mysqlManager(LockingSqlMiddleware $locking): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setMiddlewares([$locking]);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(RaceHarness::sqliteParams($this->path), $config), $config);
    }
}
