<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

/**
 * Minimal ManagerRegistry for repository integration tests.
 */
final class TestManagerRegistry implements ManagerRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return ['default' => $this->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'default'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ObjectManager
    {
        return $this->entityManager;
    }
}
