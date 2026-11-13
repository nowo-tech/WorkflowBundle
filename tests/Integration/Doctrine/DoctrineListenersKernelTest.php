<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Doctrine;

use Composer\InstalledVersions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function implode;
use function sys_get_temp_dir;

/**
 * 1.15.2: the bundle's Doctrine listeners are registered by the installed DoctrineBundle, 2.x or 3.x (3.x ignores the
 * `doctrine.event_subscriber` tag 1.15.1 used): the NO PAD binary collation lands in the generated schema
 * (`doctrine:migrations:diff` input) and the table prefix in the class metadata.
 */
final class DoctrineListenersKernelTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-workflow-doctrine-listeners');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function servers(): iterable
    {
        yield 'MySQL 8.4' => ['mysql://app:app@127.0.0.1:3306/app', '8.4.3', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.0.16' => ['mysql://app:app@127.0.0.1:3306/app', '8.0.16', 'utf8mb4_bin'];
        yield 'MariaDB 11.4' => ['mysql://app:app@127.0.0.1:3306/app', '11.4.4-MariaDB', 'utf8mb4_nopad_bin'];
    }

    #[Test]
    #[DataProvider('servers')]
    public function theCollationListenerRunsUnderTheInstalledDoctrineBundle(string $url, string $serverVersion, string $collation): void
    {
        $manager = $this->boot(new DoctrineListenersTestKernel($url, $serverVersion));
        self::assertTrue($manager->getEventManager()->hasListeners(ToolEvents::postGenerateSchemaTable), $this->doctrineBundle());

        $schema = (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata());
        $keys   = [
            WorkflowDefinition::class => ['tenant_key', 'slug'],
            WorkflowSubjectPin::class => ['tenant_key', 'workflow_slug', 'subject_ref'],
        ];
        foreach ($keys as $class => $columns) {
            $table = $schema->getTable($manager->getClassMetadata($class)->getTableName());
            foreach ($columns as $column) {
                self::assertSame($collation, $table->getColumn($column)->getCollation(), $class . '::' . $column . ' (' . $this->doctrineBundle() . ')');
            }
        }

        $sql = implode("\n", $schema->toSql($manager->getConnection()->getDatabasePlatform()));
        self::assertStringContainsString('COLLATE `' . $collation . '`', $sql);
    }

    #[Test]
    public function theTablePrefixListenerRunsUnderTheInstalledDoctrineBundle(): void
    {
        $manager = $this->boot(new DoctrineListenersTestKernel('mysql://app:app@127.0.0.1:3306/app', '8.4.3', 'acme_wf_'));

        self::assertSame('acme_wf_definition', $manager->getClassMetadata(WorkflowDefinition::class)->getTableName(), $this->doctrineBundle());
        self::assertSame('acme_wf_subject_pin', $manager->getClassMetadata(WorkflowSubjectPin::class)->getTableName());
        $schema = (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata());
        self::assertSame('utf8mb4_0900_bin', $schema->getTable('acme_wf_subject_pin')->getColumn('subject_ref')->getCollation());
    }

    private function boot(DoctrineListenersTestKernel $kernel): EntityManagerInterface
    {
        $kernel->boot();
        $doctrine = $kernel->getContainer()->get('doctrine');
        $manager  = $doctrine instanceof ManagerRegistry ? $doctrine->getManagerForClass(WorkflowDefinition::class) : null;
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('No manager for the bundle entities.');
        }

        return $manager;
    }

    private function doctrineBundle(): string
    {
        return 'doctrine/doctrine-bundle ' . InstalledVersions::getPrettyVersion('doctrine/doctrine-bundle');
    }
}
