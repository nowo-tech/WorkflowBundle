<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Util;

use Nowo\WorkflowBundle\Tests\Support\__CG__\Nowo\WorkflowBundle\Tests\Support\Subject\WorkflowSubjectBase as ProxySubject;
use Nowo\WorkflowBundle\Tests\Support\Subject\WorkflowSubjectBase;
use Nowo\WorkflowBundle\Tests\Support\Subject\WorkflowSubjectChild;
use Nowo\WorkflowBundle\Tests\Support\Subject\WorkflowSubjectMarkerInterface;
use Nowo\WorkflowBundle\Util\SubjectClass;
use PHPUnit\Framework\TestCase;

final class SubjectClassTest extends TestCase
{
    public function testRealStripsDoctrineProxyPrefix(): void
    {
        self::assertSame(WorkflowSubjectBase::class, SubjectClass::real(new ProxySubject()));
        self::assertSame(WorkflowSubjectBase::class, SubjectClass::real(ProxySubject::class));
        self::assertSame('App\\Entity\\Order', SubjectClass::real('Proxies\\__CG__\\App\\Entity\\Order'));
        self::assertSame(WorkflowSubjectChild::class, SubjectClass::real(new WorkflowSubjectChild()));
    }

    public function testHierarchyListsRealClassParentsAndInterfaces(): void
    {
        self::assertSame(
            [WorkflowSubjectChild::class, WorkflowSubjectBase::class, WorkflowSubjectMarkerInterface::class],
            SubjectClass::hierarchy(WorkflowSubjectChild::class),
        );
        self::assertSame(
            [WorkflowSubjectBase::class, WorkflowSubjectMarkerInterface::class],
            SubjectClass::hierarchy(ProxySubject::class),
        );
        self::assertSame(['App\\Entity\\Unknown'], SubjectClass::hierarchy('App\\Entity\\Unknown'));
    }

    public function testMatches(): void
    {
        self::assertTrue(SubjectClass::matches('App\\Entity\\Order', 'App\\Entity\\Order'));
        self::assertTrue(SubjectClass::matches('Proxies\\__CG__\\App\\Entity\\Order', 'App\\Entity\\Order'));
        self::assertTrue(SubjectClass::matches(WorkflowSubjectChild::class, WorkflowSubjectBase::class));
        self::assertTrue(SubjectClass::matches(ProxySubject::class, WorkflowSubjectBase::class));
        self::assertTrue(SubjectClass::matches(WorkflowSubjectChild::class, WorkflowSubjectMarkerInterface::class));
        self::assertFalse(SubjectClass::matches(WorkflowSubjectBase::class, WorkflowSubjectChild::class));
        self::assertFalse(SubjectClass::matches('App\\Entity\\Order', 'App\\Entity\\Other'));
    }
}
