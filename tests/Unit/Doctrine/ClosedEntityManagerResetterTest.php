<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Doctrine\ClosedEntityManagerResetter;
use PHPUnit\Framework\TestCase;

final class ClosedEntityManagerResetterTest extends TestCase
{
    public function testResetsClosedManagerByName(): void
    {
        $other = $this->createMock(EntityManagerInterface::class);
        $em    = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['other' => $other, 'workflow' => $em]);
        $registry->expects(self::once())->method('resetManager')->with('workflow')->willReturn($em);

        ClosedEntityManagerResetter::resetIfClosed($registry, $em);
    }

    public function testDoesNothingWhenManagerIsOpen(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagers');
        $registry->expects(self::never())->method('resetManager');

        ClosedEntityManagerResetter::resetIfClosed($registry, $em);
    }

    public function testDoesNothingWhenManagerIsNotRegistered(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->createMock(EntityManagerInterface::class)]);
        $registry->expects(self::never())->method('resetManager');

        ClosedEntityManagerResetter::resetIfClosed($registry, $em);
    }

    public function testDoesNothingWithoutRegistry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('isOpen');

        ClosedEntityManagerResetter::resetIfClosed(null, $em);
    }
}
