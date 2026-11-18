<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Concurrency\SubjectMarkingGuard;
use Nowo\WorkflowBundle\Enum\WorkflowConcurrencyMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class SubjectMarkingGuardTest extends TestCase
{
    #[Test]
    public function storedMarkingsBecomeSortedPlaces(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'b';
            }
        };

        self::assertSame([], SubjectMarkingGuard::places(null));
        self::assertSame([], SubjectMarkingGuard::places(''));
        self::assertSame([], SubjectMarkingGuard::places([]));
        self::assertSame(['draft'], SubjectMarkingGuard::places('draft'));
        self::assertSame(['7'], SubjectMarkingGuard::places(7));
        self::assertSame(['lock'], SubjectMarkingGuard::places(WorkflowConcurrencyMode::Lock));
        self::assertSame(['a', 'b'], SubjectMarkingGuard::places(['b' => 1, 'a' => 1]));
        self::assertSame(['b'], SubjectMarkingGuard::places($stringable));
        self::assertSame([''], SubjectMarkingGuard::places(new stdClass()));
        // Audit TF-L3: a place => tokens map, also for places named "0" and "1".
        self::assertSame(['0'], SubjectMarkingGuard::places(['0' => 1]));
        self::assertSame(['1'], SubjectMarkingGuard::places(['1' => 1]));
        self::assertNotSame(SubjectMarkingGuard::marking(['0' => 1]), SubjectMarkingGuard::marking(['1' => 1]));
        // Token counts count: a place holding two tokens is not the same marking.
        self::assertSame(['a' => 2, 'b' => 1], SubjectMarkingGuard::marking(['b' => 1, 'a' => '2']));
        self::assertSame(['a' => 1], SubjectMarkingGuard::marking(['a' => true]));
    }

    #[Test]
    public function theSubjectManagerIsTheOneThatHoldsItAsAPersistedEntity(): void
    {
        $subject = new stdClass();
        $default = $this->manager(true, true, false);
        $other   = $this->manager(true, false, false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($other);
        self::assertSame($default, (new SubjectMarkingGuard($default, $registry))->managerOf($subject), 'the class manager does not hold it');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        self::assertSame($default, (new SubjectMarkingGuard($default, $registry))->managerOf($subject));

        self::assertNull((new SubjectMarkingGuard($this->manager(false, true, false)))->managerOf($subject), 'closed');
        self::assertNull((new SubjectMarkingGuard($this->manager(true, true, true)))->managerOf($subject), 'not inserted yet');
        self::assertNull((new SubjectMarkingGuard($this->manager(true, false, false)))->managerOf($subject), 'not managed');
    }

    private function manager(bool $open, bool $contains, bool $scheduledForInsert): EntityManagerInterface
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('isScheduledForInsert')->willReturn($scheduledForInsert);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);
        $manager->method('contains')->willReturn($contains);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $manager;
    }
}
