<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\InheritedRaceSubject;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\InheritedRaceSubjectChild;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\PetriRaceSubject;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceHarness;
use Nowo\WorkflowBundle\Tests\Support\Concurrency\RaceSubject;
use PHPUnit\Framework\Attributes\Test;

/**
 * Race scenarios shared by the SQLite suite and the real-database suites (audit TF): JSON (multiple state) markings,
 * proxies, JOINED inheritance and the application's transaction.
 *
 * @property EntityManagerInterface $a
 * @property EntityManagerInterface $b
 */
trait ConcurrentApplyScenarios
{
    #[Test]
    public function aStaleParallelBranchOfAPetriNetIsAConflictAndNoBranchIsLost(): void
    {
        $id       = $this->persisted(new PetriRaceSubject(['left' => 1, 'right' => 1]));
        $subjectA = $this->a->find(PetriRaceSubject::class, $id);
        $subjectB = $this->b->find(PetriRaceSubject::class, $id);
        self::assertInstanceOf(PetriRaceSubject::class, $subjectA);
        self::assertInstanceOf(PetriRaceSubject::class, $subjectB);

        RaceHarness::applicator($this->a)->apply($subjectA, RaceHarness::PETRI_SLUG, 'left');

        try {
            RaceHarness::applicator($this->b)->apply($subjectB, RaceHarness::PETRI_SLUG, 'right');
            self::fail('The other branch, applied on a stale marking, must conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_MARKING_CHANGED, $conflict->reason);
            self::assertSame(['left', 'right'], $conflict->expectedPlaces);
            self::assertSame(['left_done', 'right'], $conflict->currentPlaces);
        }
        self::assertSame(['left_done' => 1, 'right' => 1], RaceHarness::storedPetriMarking($this->a, $id));

        $this->b->refresh($subjectB);
        $applicator = RaceHarness::applicator($this->b);
        $applicator->apply($subjectB, RaceHarness::PETRI_SLUG, 'right');
        $applicator->apply($subjectB, RaceHarness::PETRI_SLUG, 'join');
        self::assertSame(['end' => 1], RaceHarness::storedPetriMarking($this->a, $id));
    }

    #[Test]
    public function aJsonMarkingWithReorderedKeysIsNoConflict(): void
    {
        $id = $this->persisted(new PetriRaceSubject(['left' => 1, 'right' => 1]));
        $this->a->getConnection()->executeStatement('UPDATE wfrt_petri_race_subject SET marking = ? WHERE id = ?', ['{"right": 1,  "left": 1}', $id]);
        $subject = $this->b->find(PetriRaceSubject::class, $id);
        self::assertInstanceOf(PetriRaceSubject::class, $subject);

        RaceHarness::applicator($this->b)->apply($subject, RaceHarness::PETRI_SLUG, 'left');

        self::assertSame(['left_done' => 1, 'right' => 1], RaceHarness::storedPetriMarking($this->a, $id));
    }

    #[Test]
    public function aChangedTokenCountIsAConflict(): void
    {
        $id      = $this->persisted(new PetriRaceSubject(['left' => 1, 'right' => 1]));
        $subject = $this->b->find(PetriRaceSubject::class, $id);
        self::assertInstanceOf(PetriRaceSubject::class, $subject);
        $this->a->getConnection()->executeStatement('UPDATE wfrt_petri_race_subject SET marking = ? WHERE id = ?', ['{"left": 2, "right": 1}', $id]);

        $this->expectException(WorkflowConflictException::class);
        RaceHarness::applicator($this->b)->apply($subject, RaceHarness::PETRI_SLUG, 'left');
    }

    #[Test]
    public function anUninitialisedProxyIsNoConflict(): void
    {
        $id = $this->persisted(new RaceSubject('draft'));
        $this->b->clear();
        $reference = $this->b->getReference(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $reference);

        RaceHarness::applicator($this->b)->apply($reference, RaceHarness::SLUG, 'submit');

        self::assertSame('review', RaceHarness::storedStatus($this->a, $id));
    }

    #[Test]
    public function aSubjectOfAJoinedInheritanceRootIsLockedOnItsOwnTable(): void
    {
        $root  = $this->persisted(new InheritedRaceSubject());
        $child = $this->persisted(new InheritedRaceSubjectChild());

        foreach ([$root, $child] as $id) {
            $stale   = $this->a->find(InheritedRaceSubject::class, $id);
            $subject = $this->b->find(InheritedRaceSubject::class, $id);
            self::assertInstanceOf(InheritedRaceSubject::class, $stale);
            self::assertInstanceOf(InheritedRaceSubject::class, $subject);

            RaceHarness::applicator($this->b)->apply($subject, RaceHarness::INHERITED_SLUG, 'submit');
            self::assertSame('review', RaceHarness::storedStatus($this->a, $id, 'wfrt_inherited_race_subject'));

            try {
                RaceHarness::applicator($this->a)->apply($stale, RaceHarness::INHERITED_SLUG, 'submit');
                self::fail('The stale instance must conflict.');
            } catch (WorkflowConflictException $conflict) {
                self::assertSame(['review'], $conflict->currentPlaces);
            }
        }
        self::assertInstanceOf(InheritedRaceSubjectChild::class, $this->b->find(InheritedRaceSubject::class, $child));
    }

    #[Test]
    public function aConflictInsideTheApplicationTransactionKeepsTheApplicationWork(): void
    {
        $id       = $this->persisted(new RaceSubject('draft'));
        $subjectA = $this->a->find(RaceSubject::class, $id);
        $subjectB = $this->b->find(RaceSubject::class, $id);
        self::assertInstanceOf(RaceSubject::class, $subjectA);
        self::assertInstanceOf(RaceSubject::class, $subjectB);
        RaceHarness::applicator($this->a)->apply($subjectA, RaceHarness::SLUG, 'submit');

        $connection = $this->b->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO wfrt_race_subject (status, note) VALUES ('host', 'host work')");
        try {
            RaceHarness::applicator($this->b, flushAfterApply: false)->apply($subjectB, RaceHarness::SLUG, 'submit');
            self::fail('The stale subject must conflict.');
        } catch (WorkflowConflictException $conflict) {
            self::assertSame(WorkflowConflictException::REASON_MARKING_CHANGED, $conflict->reason);
        }
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'the application transaction is still open');
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'), 'and usable');
        $connection->commit();

        self::assertSame(1, (int) $this->a->getConnection()->fetchOne("SELECT COUNT(*) FROM wfrt_race_subject WHERE note = 'host work'"));
    }

    private function persisted(object $subject): int
    {
        $this->a->persist($subject);
        $this->a->flush();
        $this->a->clear();
        $id = $this->a->getUnitOfWork()->getSingleIdentifierValue($subject);

        return (int) $id;
    }
}
