<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use PHPUnit\Framework\TestCase;
use Stringable;

final class WorkflowContextTest extends TestCase
{
    public function testGetHasAndSortedParameters(): void
    {
        $context = new WorkflowContext('App\\Entity\\X', ['b' => '2', 'a' => '1']);

        self::assertTrue($context->has('a'));
        self::assertSame('1', $context->get('a'));
        self::assertNull($context->get('missing'));
        self::assertSame(['a' => '1', 'b' => '2'], $context->sortedParameters());
    }

    public function testWithParameterAndSubjectClassAreImmutable(): void
    {
        $original = new WorkflowContext(null, ['tenant' => 'acme']);
        $updated  = $original->withParameter('region', 'eu')->withSubjectClass('App\\Entity\\Order');

        self::assertNull($original->subjectClass);
        self::assertSame('App\\Entity\\Order', $updated->subjectClass);
        self::assertSame(['tenant' => 'acme', 'region' => 'eu'], $updated->parameters);
    }

    public function testNonStringParameterValuesAreNormalizedToStrings(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'acme';
            }
        };

        $context = new WorkflowContext('App\\Entity\\Order', [
            'amount'   => 1500,
            'ratio'    => 1.5,
            'urgent'   => true,
            'archived' => false,
            'tenant'   => $stringable,
            'missing'  => null,
            7          => 'numeric-key',
        ]);

        self::assertSame('1500', $context->get('amount'));
        self::assertSame('1.5', $context->get('ratio'));
        self::assertSame('true', $context->get('urgent'));
        self::assertSame('false', $context->get('archived'));
        self::assertSame('acme', $context->get('tenant'));
        self::assertNull($context->get('missing'));
        self::assertFalse($context->has('missing'));
        self::assertSame('numeric-key', $context->get('7'));
        self::assertSame('1500', $context->withParameter('x', 'y')->get('amount'));
    }

    public function testRejectsNonScalarParameterValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow context parameter "tenant" must be a scalar or Stringable value, "array" given.');

        new WorkflowContext(null, ['tenant' => ['acme']]);
    }
}
