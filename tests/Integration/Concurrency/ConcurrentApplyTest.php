<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\WorkflowBundle\Concurrency\WorkflowConcurrencyPolicy;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceSubject;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\VersionedRaceSubject;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * REQ-RUNTIME-003: two connections (two entity managers) on one SQLite file race to apply a transition to the same
 * subject. The default suite runs it; ConcurrentApplyRealDatabaseTest runs the same races on MySQL, MariaDB and
 * PostgreSQL with real row locks.
 */
final class ConcurrentApplyTest extends TestCase
{
    private string $path;

    private EntityManagerInterface $a;

    private EntityManagerInterface $b;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'wfrt');
        $this->a    = RaceHarness::entityManager(RaceHarness::sqliteParams($this->path));
        $this->b    = RaceHarness::entityManager(RaceHarness::sqliteParams($this->path));
        RaceHarness::createSchema($this->a);
    }

    protected function tearDown(): void
    {
        $this->a->getConnection()->close();
        $this->b->getConnection()->close();
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function twoAppliesFromTheSamePlaceGiveOneTransitionAndTheLoserAConflict(): void
    {
        $id       = $this->subject('draft');
        $subjectA = $this->a->find(RaceSubject::class, $id);
        $subjectB = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a)->apply($subjectA, RaceHarness::SLUG, 'submit');

        try {
            RaceHarness::applicator($this->b)->apply($subjectB, RaceHarness::SLUG, 'submit');
            self::fail('The second apply from the same place must lose.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_MARKING_CHANGED, $conflict->reason);
            self::assertSame(RaceHarness::SLUG, $conflict->workflowSlug);
            self::assertSame(['draft'], $conflict->expectedPlaces);
            self::assertSame(['review'], $conflict->currentPlaces);
            self::assertSame(1, $conflict->retryAfterSeconds());
            self::assertSame(WorkflowConflictException::MESSAGE, $conflict->getMessage());
        }

        self::assertSame('review', RaceHarness::storedStatus($this->b, $id));
        self::assertSame('draft', $subjectB->getStatus(), 'the loser applied nothing');
        self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());
        self::assertTrue($this->b->isOpen());

        // The loser reloads and continues from the winner's place.
        $this->b->refresh($subjectB);
        RaceHarness::applicator($this->b)->apply($subjectB, RaceHarness::SLUG, 'approve');
        self::assertSame('done', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function theAutomaticTransitionsRunInTheSameUnit(): void
    {
        $definition = RaceHarness::definition(automatic: true);
        $id         = $this->subject('review');
        $subject    = $this->a->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);

        $automatic = RaceHarness::applicator($this->a, definitions: [$definition])->applyWithAutomatic($subject, RaceHarness::SLUG, 'approve');

        self::assertSame(['close'], $automatic);
        self::assertSame('closed', RaceHarness::storedStatus($this->b, $id));
        self::assertSame([], RaceHarness::applicator($this->a, definitions: [$definition])->applyAutomaticTransitions($subject, RaceHarness::SLUG));
    }

    #[Test]
    public function aConcurrentAutomaticRunIsAConflictToo(): void
    {
        $definition = RaceHarness::definition(automatic: true);
        $id         = $this->subject('done');
        $subjectA   = $this->a->find(RaceSubject::class, $id);
        $subjectB   = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);

        self::assertSame(['close'], RaceHarness::applicator($this->a, definitions: [$definition])->applyAutomaticTransitions($subjectA, RaceHarness::SLUG));

        $this->expectException(WorkflowConflictException::class);
        RaceHarness::applicator($this->b, definitions: [$definition])->applyAutomaticTransitions($subjectB, RaceHarness::SLUG);
    }

    #[Test]
    public function aSubjectDeletedConcurrentlyIsAConflict(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);
        $this->a->getConnection()->executeStatement('DELETE FROM wfrt_race_subject WHERE id = ?', [$id]);

        try {
            RaceHarness::applicator($this->b)->apply($subject, RaceHarness::SLUG, 'submit');
            self::fail('A deleted subject must be a conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_SUBJECT_MISSING, $conflict->reason);
            self::assertSame(['draft'], $conflict->expectedPlaces);
            self::assertSame([], $conflict->currentPlaces);
        }
    }

    #[Test]
    public function theNoneModeKeepsThe119BehaviourAndLosesTheUpdate(): void
    {
        $policy   = new WorkflowConcurrencyPolicy('lock', [RaceHarness::SLUG => 'none']);
        $id       = $this->subject('draft');
        $subjectA = $this->a->find(RaceSubject::class, $id);
        $subjectB = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a, $policy)->apply($subjectA, RaceHarness::SLUG, 'submit');
        RaceHarness::applicator($this->b, $policy)->apply($subjectB, RaceHarness::SLUG, 'submit');

        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
        self::assertSame('review', $subjectB->getStatus(), 'both applied: the double transition of 1.19');
    }

    #[Test]
    public function theOptimisticModeLetsTheVersionDecide(): void
    {
        $policy   = new WorkflowConcurrencyPolicy('optimistic');
        $id       = $this->versionedSubject();
        $subjectA = $this->a->find(VersionedRaceSubject::class, $id);
        $subjectB = $this->b->find(VersionedRaceSubject::class, $id);
        self::assertInstanceOf(VersionedRaceSubject::class, $subjectA);
        self::assertInstanceOf(VersionedRaceSubject::class, $subjectB);

        // A concurrent write that leaves the marking alone but bumps the version.
        $subjectA->setNote('edited');
        $this->a->flush();

        $registry = new TestManagerRegistry($this->b);
        try {
            RaceHarness::applicator($this->b, $policy, true, $registry)->apply($subjectB, RaceHarness::VERSIONED_SLUG, 'submit');
            self::fail('The versioned UPDATE must refuse the stale subject.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_VERSION_CHANGED, $conflict->reason);
            self::assertInstanceOf(OptimisticLockException::class, $conflict->getPrevious());
        }

        self::assertSame('draft', RaceHarness::storedStatus($this->a, $id, 'wfrt_versioned_race_subject'));
        self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());
    }

    #[Test]
    public function theOptimisticModeComparesTheMarkingFirst(): void
    {
        $policy   = new WorkflowConcurrencyPolicy('optimistic');
        $id       = $this->versionedSubject();
        $subjectA = $this->a->find(VersionedRaceSubject::class, $id);
        $subjectB = $this->b->find(VersionedRaceSubject::class, $id);
        self::assertInstanceOf(VersionedRaceSubject::class, $subjectA);
        self::assertInstanceOf(VersionedRaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a, $policy)->apply($subjectA, RaceHarness::VERSIONED_SLUG, 'submit');
        self::assertSame(2, $subjectA->getVersion());

        $this->expectException(WorkflowConflictException::class);
        RaceHarness::applicator($this->b, $policy)->apply($subjectB, RaceHarness::VERSIONED_SLUG, 'submit');
    }

    #[Test]
    public function theOptimisticModeNeedsAVersionField(): void
    {
        $subject = $this->a->find(RaceSubject::class, $this->subject('draft'));
        self::assertInstanceOf(RaceSubject::class, $subject);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no Doctrine #[Version] field');
        RaceHarness::applicator($this->a, new WorkflowConcurrencyPolicy('optimistic'))->apply($subject, RaceHarness::SLUG, 'submit');
    }

    #[Test]
    public function withFlushOffTheMarkingIsCheckedAndNothingIsFlushed(): void
    {
        $id       = $this->subject('draft');
        $subjectA = $this->a->find(RaceSubject::class, $id);
        $subjectB = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a, flushAfterApply: false)->apply($subjectA, RaceHarness::SLUG, 'submit');
        self::assertSame('draft', RaceHarness::storedStatus($this->b, $id), 'nothing flushed');
        $this->a->flush();

        $this->expectException(WorkflowConflictException::class);
        RaceHarness::applicator($this->b, flushAfterApply: false)->apply($subjectB, RaceHarness::SLUG, 'submit');
    }

    #[Test]
    public function withFlushOffInsideTheApplicationTransactionTheRowIsLocked(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);

        $this->b->wrapInTransaction(static function (EntityManagerInterface $manager) use ($subject): void {
            RaceHarness::applicator($manager, flushAfterApply: false)->apply($subject, RaceHarness::SLUG, 'submit');
        });

        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function aSubjectOfAnotherManagerIsCheckedButLeftToItsManager(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->b);

        // The applicator flushes manager A (flush_after_apply); the subject lives in manager B.
        RaceHarness::applicator($this->a, registry: $registry)->apply($subject, RaceHarness::SLUG, 'submit');

        self::assertSame('draft', RaceHarness::storedStatus($this->a, $id), 'B was not flushed by the bundle');
        $this->b->flush();
        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function aNewSubjectOrAnUnmappedMarkingHasNoRowToRaceOn(): void
    {
        $new = new RaceSubject('draft');
        $this->a->persist($new);
        RaceHarness::applicator($this->a)->apply($new, RaceHarness::SLUG, 'submit');
        self::assertNotNull($new->getId());
        self::assertSame('review', RaceHarness::storedStatus($this->b, $new->getId()));

        $phase = RaceHarness::definition();
        $phase->setMarkingProperty('phase');
        $subject = $this->a->find(RaceSubject::class, $this->subject('draft'));
        self::assertInstanceOf(RaceSubject::class, $subject);
        RaceHarness::applicator($this->a, definitions: [$phase])->apply($subject, RaceHarness::SLUG, 'submit');
        self::assertSame('review', $subject->getPhase());
    }

    #[Test]
    public function aLockHeldByTheOtherConnectionIsAConflictAndNothingIsLeftOpen(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);

        $this->a->getConnection()->executeStatement('BEGIN IMMEDIATE');
        $this->a->getConnection()->executeStatement("UPDATE wfrt_race_subject SET note = 'held' WHERE id = ?", [$id]);

        try {
            RaceHarness::applicator($this->b)->apply($subject, RaceHarness::SLUG, 'submit');
            self::fail('A write blocked by the other connection must be a conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_LOCK, $conflict->reason);
            self::assertInstanceOf(TransientStorageFailure::class, $conflict->getPrevious());
            self::assertInstanceOf(LockWaitTimeoutException::class, $conflict->getPrevious()->getPrevious());
        } finally {
            $this->a->getConnection()->executeStatement('ROLLBACK');
        }

        self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());
        self::assertSame('draft', RaceHarness::storedStatus($this->b, $id));
    }

    #[Test]
    public function aLockInTheApplicationUnitIsAConflict(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);

        $this->a->getConnection()->executeStatement('BEGIN EXCLUSIVE');
        try {
            RaceHarness::applicator($this->b, flushAfterApply: false)->apply($subject, RaceHarness::SLUG, 'submit');
            self::fail('A read blocked by the other connection must be a conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_LOCK, $conflict->reason);
            self::assertInstanceOf(LockWaitTimeoutException::class, $conflict->getPrevious());
        } finally {
            $this->a->getConnection()->executeStatement('ROLLBACK');
        }
        self::assertSame('draft', $subject->getStatus());
    }

    #[Test]
    public function otherFailuresPassThroughUnchanged(): void
    {
        $id      = $this->subject('draft');
        $subject = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subject);

        try {
            RaceHarness::applicator($this->b)->apply($subject, RaceHarness::SLUG, 'approve');
            self::fail('A blocked transition must throw.');
        } catch (WorkflowTransitionBlockedException) {
            self::assertSame(0, $this->b->getConnection()->getTransactionNestingLevel());
        }

        $this->a->getConnection()->executeStatement('DROP TABLE wfrt_race_subject');
        $this->expectException(TableNotFoundException::class);
        RaceHarness::applicator($this->b, flushAfterApply: false)->apply($subject, RaceHarness::SLUG, 'submit');
    }

    private function subject(?string $status): int
    {
        $subject = new RaceSubject($status);
        $this->a->persist($subject);
        $this->a->flush();
        $this->a->clear();

        return (int) $subject->getId();
    }

    private function versionedSubject(): int
    {
        $subject = new VersionedRaceSubject('draft');
        $this->a->persist($subject);
        $this->a->flush();
        $this->a->clear();

        return (int) $subject->getId();
    }
}
