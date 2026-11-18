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
        self::assertSame(['a', 'b'], SubjectMarkingGuard::places(['b', 'a']));
        self::assertSame(['', 'b'], SubjectMarkingGuard::places([$stringable, new stdClass()]));
        self::assertSame(['b'], SubjectMarkingGuard::places($stringable));
        self::assertSame([''], SubjectMarkingGuard::places(new stdClass()));
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
