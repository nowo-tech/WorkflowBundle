<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use Nowo\WorkflowBundle\Model\WorkflowContext;
use PHPUnit\Framework\TestCase;

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
}
