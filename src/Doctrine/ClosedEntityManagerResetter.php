<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reopens an EntityManager closed by a failed flush.
 *
 * Without a kernel reset between requests (long-running workers) a closed manager would stay
 * closed for every later request handled by the same process.
 */
final class ClosedEntityManagerResetter
{
    public static function resetIfClosed(?ManagerRegistry $managerRegistry, EntityManagerInterface $entityManager): void
    {
        if (!$managerRegistry instanceof ManagerRegistry || $entityManager->isOpen()) {
            return;
        }

        foreach ($managerRegistry->getManagers() as $name => $manager) {
            if ($manager === $entityManager) {
                $managerRegistry->resetManager($name);

                return;
            }
        }
    }
}
