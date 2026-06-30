<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Exception\WorkflowAmbiguousMatchException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use PHPUnit\Framework\TestCase;

final class WorkflowResolverTest extends TestCase
{
    private const SUBJECT = 'App\\Entity\\DemoPurchaseOrder';

    public function testResolvesDefaultWhenNoParameters(): void
    {
        $default  = $this->definition('default_po', self::SUBJECT, []);
        $specific = $this->definition('acme_eu_high_po', self::SUBJECT, [
            'tenant'      => 'acme',
            'region'      => 'eu',
            'amount_tier' => 'high',
        ]);

        $resolver = $this->resolver($default, $specific);
        $resolved = $resolver->resolve(new WorkflowContext(self::SUBJECT, []));

        self::assertSame('default_po', $resolved->getSlug());
    }

    public function testResolvesSingleParameterMatch(): void
    {
        $globex = $this->definition('globex_expense', 'App\\Entity\\DemoExpense', ['tenant' => 'globex']);

        $resolver = $this->resolver($globex);
        $resolved = $resolver->resolve(new WorkflowContext('App\\Entity\\DemoExpense', [
            'tenant'     => 'globex',
            'department' => 'finance',
        ]));

        self::assertSame('globex_expense', $resolved->getSlug());
    }

    public function testResolvesTwoParameterMatch(): void
    {
        $finance = $this->definition('acme_finance_expense', 'App\\Entity\\DemoExpense', [
            'tenant'     => 'acme',
            'department' => 'finance',
        ], priority: 20);
        $globex = $this->definition('globex_expense', 'App\\Entity\\DemoExpense', ['tenant' => 'globex'], priority: 5);

        $resolver = $this->resolver($finance, $globex);
        $resolved = $resolver->resolve(new WorkflowContext('App\\Entity\\DemoExpense', [
            'tenant'     => 'acme',
            'department' => 'finance',
        ]));

        self::assertSame('acme_finance_expense', $resolved->getSlug());
    }

    public function testThreeParameterMatchWinsOverTwoParameterFallback(): void
    {
        $high = $this->definition('acme_eu_high_po', self::SUBJECT, [
            'tenant'      => 'acme',
            'region'      => 'eu',
            'amount_tier' => 'high',
        ], priority: 100);
        $eu = $this->definition('acme_eu_po', self::SUBJECT, [
            'tenant' => 'acme',
            'region' => 'eu',
        ], priority: 50);

        $resolver = $this->resolver($high, $eu);
        $resolved = $resolver->resolve(new WorkflowContext(self::SUBJECT, [
            'tenant'      => 'acme',
            'region'      => 'eu',
            'amount_tier' => 'high',
        ]));

        self::assertSame('acme_eu_high_po', $resolved->getSlug());
    }

    public function testTwoParameterFallbackWhenThirdDoesNotMatch(): void
    {
        $high = $this->definition('acme_eu_high_po', self::SUBJECT, [
            'tenant'      => 'acme',
            'region'      => 'eu',
            'amount_tier' => 'high',
        ]);
        $eu = $this->definition('acme_eu_po', self::SUBJECT, [
            'tenant' => 'acme',
            'region' => 'eu',
        ]);

        $resolver = $this->resolver($high, $eu);
        $resolved = $resolver->resolve(new WorkflowContext(self::SUBJECT, [
            'tenant'      => 'acme',
            'region'      => 'eu',
            'amount_tier' => 'low',
        ]));

        self::assertSame('acme_eu_po', $resolved->getSlug());
    }

    public function testPriorityBreaksEqualSpecificityTie(): void
    {
        $low  = $this->definition('po_low', self::SUBJECT, ['tenant' => 'acme'], priority: 10);
        $high = $this->definition('po_high', self::SUBJECT, ['tenant' => 'acme'], priority: 90);

        $resolver = $this->resolver($low, $high);
        $resolved = $resolver->resolve(new WorkflowContext(self::SUBJECT, ['tenant' => 'acme']));

        self::assertSame('po_high', $resolved->getSlug());
    }

    public function testThrowsWhenNoMatch(): void
    {
        $this->expectException(WorkflowNotFoundException::class);
        $this->resolver($this->definition('only_acme', self::SUBJECT, ['tenant' => 'acme']))
            ->resolve(new WorkflowContext(self::SUBJECT, ['tenant' => 'other']));
    }

    public function testThrowsOnAmbiguousEqualMatch(): void
    {
        $this->expectException(WorkflowAmbiguousMatchException::class);
        $a = $this->definition('po_a', self::SUBJECT, ['tenant' => 'acme'], priority: 10);
        $b = $this->definition('po_b', self::SUBJECT, ['tenant' => 'acme'], priority: 10);

        $this->resolver($a, $b)->resolve(new WorkflowContext(self::SUBJECT, ['tenant' => 'acme']));
    }

    public function testResolveSlugAndFindMatching(): void
    {
        $definition = $this->definition('only', self::SUBJECT, []);
        $resolver   = $this->resolver($definition);

        self::assertSame('only', $resolver->resolveSlug(new WorkflowContext(self::SUBJECT, [])));
        self::assertSame([$definition], $resolver->findMatching(new WorkflowContext(self::SUBJECT, [])));
    }

    public function testResolveForSubjectWithDefaultContext(): void
    {
        $this->definition('only', self::SUBJECT, []);
        $subject = new class {
        };
        $subjectClass = $subject::class;

        $def      = $this->definition('only', $subjectClass, []);
        $resolved = $this->resolver($def)->resolveForSubject($subject);

        self::assertSame('only', $resolved->getSlug());
    }

    public function testResolveSlugForSubject(): void
    {
        $subjectClass = WorkflowResolverAnonymousSubject::class;
        $definition   = $this->definition('only', $subjectClass, []);

        self::assertSame('only', $this->resolver($definition)->resolveSlugForSubject(new WorkflowResolverAnonymousSubject()));
    }

    public function testResolveSlug(): void
    {
        $definition = $this->definition('only', self::SUBJECT, []);

        self::assertSame('only', $this->resolver($definition)->resolveSlug(new WorkflowContext(self::SUBJECT, [])));
    }

    public function testResolveForSubjectWithWorkflowContextAwareInterface(): void
    {
        $subjectClass = WorkflowResolverContextAwareSubject::class;
        $definition   = $this->definition('ctx', $subjectClass, ['tenant' => 'acme']);
        $subject      = new WorkflowResolverContextAwareSubject(['tenant' => 'acme']);

        $resolved = $this->resolver($definition)->resolveForSubject($subject);

        self::assertSame('ctx', $resolved->getSlug());
    }

    /** @param array<string, string> $rules */
    private function definition(string $slug, string $subjectClass, array $rules, int $priority = 0): WorkflowDefinition
    {
        $definition = new WorkflowDefinition($slug, $slug, 'draft', $subjectClass);
        $definition->setPriority($priority);

        foreach ($rules as $key => $value) {
            $definition->addMatchRule(new WorkflowMatchRule($key, $value));
        }

        return $definition;
    }

    private function resolver(WorkflowDefinition ...$definitions): WorkflowResolver
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findEnabledCandidates')->willReturnCallback(
            static function (?string $subjectClass) use ($definitions): array {
                return array_values(array_filter(
                    $definitions,
                    static fn (WorkflowDefinition $definition): bool => $subjectClass === null
                        || $definition->getSubjectClass() === $subjectClass,
                ));
            },
        );

        return new WorkflowResolver($repository);
    }
}

final class WorkflowResolverContextAwareSubject implements \Nowo\WorkflowBundle\Contract\WorkflowContextAwareInterface
{
    /** @param array<string, string> $parameters */
    public function __construct(
        private readonly array $parameters,
    ) {
    }

    public function getWorkflowContext(): WorkflowContext
    {
        return new WorkflowContext(self::class, $this->parameters);
    }
}

final class WorkflowResolverAnonymousSubject
{
}
