<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Model\WorkflowActorRef;
use Nowo\WorkflowBundle\Model\WorkflowMarkingView;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

use const INF;
use const NAN;

final class TenancyValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): object}>
     */
    public static function invalid(): iterable
    {
        yield 'empty tenant' => [static fn (): object => new WorkflowTenant('')];
        yield 'long tenant' => [static fn (): object => new WorkflowTenant(str_repeat('a', 129))];
        yield 'control tenant' => [static fn (): object => new WorkflowTenant("a\nb")];
        yield 'empty subject ref' => [static fn (): object => new WorkflowSubjectRef('')];
        yield 'long subject ref' => [static fn (): object => new WorkflowSubjectRef(str_repeat('a', 129))];
        yield 'empty actor' => [static fn (): object => new WorkflowActorRef('')];
        yield 'NUL actor' => [static fn (): object => new WorkflowActorRef("a\0")];
        yield 'variable name' => [static fn (): object => new WorkflowSubjectVariables(['Days' => 1])];
        yield 'variable index' => [static fn (): object => new WorkflowSubjectVariables([1])];
        yield 'object value' => [static fn (): object => new WorkflowSubjectVariables(['days' => new stdClass()])];
        yield 'array value' => [static fn (): object => new WorkflowSubjectVariables(['days' => [1]])];
        yield 'NAN' => [static fn (): object => new WorkflowSubjectVariables(['days' => NAN])];
        yield 'INF' => [static fn (): object => new WorkflowSubjectVariables(['days' => INF])];
        yield 'long string' => [static fn (): object => new WorkflowSubjectVariables(['type' => str_repeat('a', 1025)])];
    }

    /**
     * @param callable(): object $factory
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidValuesAreRefused(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    #[Test]
    public function validValues(): void
    {
        self::assertSame('org-a', (new WorkflowTenant('org-a'))->key);
        self::assertSame('', WorkflowTenant::storageKey(null));
        self::assertNull(WorkflowTenant::fromStorageKey(''));
        self::assertSame('x', WorkflowTenant::fromStorageKey('x')?->key);
        self::assertSame('leave-1', (new WorkflowSubjectRef('leave-1'))->id);
        self::assertSame('bob', (new WorkflowActorRef('bob'))->id);

        $variables = new WorkflowSubjectVariables(['days' => 2, 'manager' => null]);
        self::assertTrue($variables->has('manager'));
        self::assertFalse($variables->has('type'));
        self::assertNull($variables->get('type'));
        self::assertSame(['days' => 2, 'manager' => null], $variables->all());

        $pin = new WorkflowSubjectPin('org-a', 'leave', 'leave-1', new WorkflowDefinition('Leave', 'leave', 'a', 'App\\Leave'));
        self::assertNull($pin->getId());
        self::assertSame(['org-a', 'leave', 'leave-1', 'leave'], [$pin->getTenantKey(), $pin->getWorkflowSlug(), $pin->getSubjectRef(), $pin->getDefinition()->getSlug()]);

        $marking = new WorkflowMarkingView('leave', 1, null, []);
        self::assertSame('', $marking->place());
        self::assertFalse($marking->has('requested'));
    }
}
