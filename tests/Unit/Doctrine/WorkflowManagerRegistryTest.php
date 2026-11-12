<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityRepository;
use LogicException;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Doctrine\WorkflowManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\TestCase;

use function dirname;

final class WorkflowManagerRegistryTest extends TestCase
{
    public function testBundleEntitiesResolveToThePrivateManagerAndTheRestIsDelegated(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\WorkflowBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]);
        $private  = new WorkflowEntityManager($doctrine);
        $registry = new WorkflowManagerRegistry($doctrine, $private);

        self::assertSame($private, $registry->getManagerForClass(WorkflowDefinition::class));
        self::assertTrue(WorkflowManagerRegistry::isBundleEntity('Proxies\\__CG__\\' . WorkflowDefinition::class), 'Doctrine proxy class names count.');
        self::assertNull($registry->getManagerForClass(self::class));
        self::assertInstanceOf(EntityRepository::class, $registry->getRepository(WorkflowPlace::class));
        self::assertInstanceOf(EntityRepository::class, $registry->getRepository(WorkflowPlace::class, 'default'));
        self::assertSame('default', $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame(['default' => 'conn.default'], $registry->getConnectionNames());
        self::assertCount(1, $registry->getConnections());
        self::assertSame('default', $registry->getDefaultManagerName());
        $host = $doctrine->getManager();
        self::assertSame($host, $registry->getManager());
        self::assertSame(['default' => $host], $registry->getManagers());
        self::assertSame(['default' => 'em.default'], $registry->getManagerNames());
        self::assertNotSame($host, $registry->resetManager(), 'Delegated to the application registry (never called by the bundle).');
        self::assertFalse(WorkflowManagerRegistry::isBundleEntity('App\\Entity\\Page'));
        self::assertSame('App\\Entity\\Page', WorkflowManagerRegistry::realClass('Proxies\\__CG__\\App\\Entity\\Page'));
        self::assertSame('App\\Entity\\Page', WorkflowManagerRegistry::realClass('App\\Entity\\Page'));
    }

    public function testPrivateManagerLifecycle(): void
    {
        $doctrine = new SqliteManagerRegistry(['default' => ['Nowo\\WorkflowBundle\\Entity' => dirname(__DIR__, 3) . '/src/Entity']]);
        $private  = new WorkflowEntityManager($doctrine);

        self::assertFalse($private->isCreated());
        $private->connectionRolledBack();
        self::assertFalse($private->isStale(), 'Nothing to mark before the manager exists.');
        $private->clear();
        self::assertFalse($private->isCreated(), 'clear() never creates the manager.');

        $live = $private->live();
        self::assertNotSame($doctrine->getManager(), $live);
        self::assertSame($doctrine->getConnection(), $live->getConnection());
        self::assertSame($live, $private->live());
        self::assertSame($live->getConfiguration(), $private->getConfiguration(), 'Decorated calls go to the live manager.');

        $private->connectionRolledBack();
        self::assertTrue($private->isStale());
        self::assertNotSame($live, $private->live(), 'A stale manager without scheduled work is replaced.');
        self::assertFalse($private->isStale());

        $private->reset();
        self::assertFalse($private->isCreated());
        $live = $private->live();
        $live->close();
        self::assertNotSame($live, $private->live(), 'A closed manager is replaced.');
    }

    public function testUnknownPropertyAndMissingMapping(): void
    {
        $private = new WorkflowEntityManager(new SqliteManagerRegistry(['default' => ['App\\Entity' => __DIR__]]));

        try {
            $private->__get('other');
            self::fail('Only $wrapped is magic.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $private->source();
    }
}
