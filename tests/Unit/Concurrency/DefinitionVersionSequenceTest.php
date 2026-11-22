<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use LogicException;
use Nowo\WorkflowBundle\Concurrency\DefinitionVersionSequence;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The edges of the version counter (since 2.2) that a real database does not reach on demand; the races themselves are
 * in tests/Integration/Concurrency/ConcurrentVersion*.
 */
final class DefinitionVersionSequenceTest extends TestCase
{
    #[Test]
    public function theCounterIsOnlyWrittenInsideATransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');
        $sequence = $this->sequence($connection);

        foreach (['ensure', 'claim'] as $method) {
            try {
                $sequence->{$method}('leave', null);
                self::fail($method . ' outside a transaction');
            } catch (LogicException $exception) {
                self::assertStringContainsString('inside the transaction', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aCounterRowHiddenByTheSnapshotIsARetryableConflict(): void
    {
        $connection = $this->openConnection();
        $connection->method('executeStatement')->willReturn(0);

        try {
            $this->sequence($connection, 7)->claim('leave', new WorkflowTenant('org-a'));
            self::fail('Conflict expected.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_VERSION_CLAIM, $conflict->reason);
            self::assertSame('leave', $conflict->workflowSlug);
            self::assertSame(7, $conflict->retryAfterSeconds());
        }
    }

    #[Test]
    public function aVanishedCounterRowIsNeverReadAsANumber(): void
    {
        $connection = $this->openConnection();
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(LogicException::class);
        $this->sequence($connection)->claim('leave', null);
    }

    #[Test]
    public function aLostSavepointNeverReplacesTheOriginalFailure(): void
    {
        $connection = $this->openConnection();
        // No counter row, no stored version.
        $connection->method('fetchOne')->willReturn(false);
        $lost = $this->uniqueViolation();
        $connection->method('executeStatement')->willThrowException($lost);
        // Audit ZH-M1: the server already dropped the savepoint (MySQL 1305 after a deadlock).
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('SAVEPOINT nowo_workflow_version_seed does not exist'));

        try {
            $this->sequence($connection)->ensure('leave', null);
            self::fail('The original failure is rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertSame($lost, $exception);
        }
    }

    #[Test]
    public function aFirstClaimThatLostTheInsertLeavesTheWinnersRow(): void
    {
        $connection = $this->openConnection();
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willThrowException($this->uniqueViolation());
        $connection->expects(self::once())->method('rollbackSavepoint')->with('nowo_workflow_version_seed');
        $connection->expects(self::never())->method('releaseSavepoint');

        $this->sequence($connection)->ensure('leave', null);
    }

    private function sequence(Connection $connection, ?int $retryAfter = 1): DefinitionVersionSequence
    {
        return new DefinitionVersionSequence(
            $connection,
            'workflow_definition_sequence',
            ['tenant' => 'tenant_key', 'slug' => 'slug', 'last' => 'last_version'],
            'workflow_definition',
            ['tenant' => 'tenant_key', 'slug' => 'slug', 'version' => 'version'],
            static fn (): bool => false,
            $retryAfter,
        );
    }

    private function openConnection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());

        return $connection;
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class('duplicate') extends RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        }, null);
    }
}
