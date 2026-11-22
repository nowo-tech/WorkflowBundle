<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.2 (REQ-RUNTIME-003 rule 4): a next version number comes from WorkflowDefinitionRepository::insertNextVersion(),
 * and the scope is checked again under that claim, as last committed inside the caller's transaction.
 */
final class WorkflowVersioningClaimTest extends TestCase
{
    #[Test]
    public function aDraftCommittedBeforeTheClaimIsTheDraftAndTheRetryAfterIsPassedOn(): void
    {
        $published = $this->definition();
        $published->publish();
        $winner = $this->definition();
        $winner->startAsDraft(null);

        foreach ([false => 'findVersions', true => 'findVersionsAsCommitted'] as $hostTransaction => $read) {
            $repository = $this->repository((bool) $hostTransaction);
            $repository->method('findDraft')->willReturn(null);
            $repository->expects(self::once())->method('insertNextVersion')
                ->with('leave', null, self::isCallable(), 9)
                ->willReturnCallback(static fn (string $slug, ?WorkflowTenant $tenant, callable $write): mixed => $write(2));
            $repository->expects(self::once())->method($read)->willReturn([$winner, $published]);
            $repository->expects(self::never())->method('save');

            self::assertSame($winner, (new WorkflowVersioning($repository, retryAfterSeconds: 9))->createDraftOf($published), $read);
        }
    }

    #[Test]
    public function aRetiredCloneIsCustomizedAgainWithTheClaimedNumber(): void
    {
        $tenant   = new WorkflowTenant('org-a');
        $template = $this->definition();
        $template->publish();
        $retired = $template->copyAsDraft($tenant, 1, 1);
        $retired->publish();
        $retired->retire();

        $repository = $this->repository(false);
        $repository->method('findCurrent')->willReturn($template);
        $repository->method('existsInScope')->willReturn(true);
        $repository->method('findVersions')->willReturn([$retired]);
        $repository->method('insertNextVersion')->willReturnCallback(static fn (string $slug, ?WorkflowTenant $t, callable $write): mixed => $write(4));
        $repository->expects(self::once())->method('save');

        $copy = (new WorkflowVersioning($repository))->cloneTemplate('leave', $tenant, true);
        self::assertSame(4, $copy->getVersion());
        self::assertTrue($copy->isPublished());
    }

    #[Test]
    public function aCloneCustomizedConcurrentlyIsRefusedUnderTheClaim(): void
    {
        $tenant   = new WorkflowTenant('org-a');
        $template = $this->definition();
        $template->publish();
        $retired = $template->copyAsDraft($tenant, 1, 1);
        $retired->publish();
        $retired->retire();
        $winner = $template->copyAsDraft($tenant, 2, 1);

        $repository = $this->repository(true);
        $repository->method('findCurrent')->willReturn($template);
        $repository->method('existsInScope')->willReturn(true);
        // Retired when checked, a concurrent clone's draft (committed) under the claim.
        $repository->method('findVersions')->willReturn([$retired]);
        $repository->method('findVersionsAsCommitted')->willReturn([$winner, $retired]);
        $repository->method('insertNextVersion')->willReturnCallback(static fn (string $slug, ?WorkflowTenant $t, callable $write): mixed => $write(3));
        $repository->expects(self::never())->method('save');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already has a workflow "leave"');
        (new WorkflowVersioning($repository))->cloneTemplate('leave', $tenant);
    }

    private function definition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'a', 'X');
        $definition->addPlace(new WorkflowPlace('a'));

        return $definition;
    }

    private function repository(bool $hostTransaction): WorkflowDefinitionRepository&MockObject
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn($hostTransaction);
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('connection')->willReturn($connection);

        return $repository;
    }
}
