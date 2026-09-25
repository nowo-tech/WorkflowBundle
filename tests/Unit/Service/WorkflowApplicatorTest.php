<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;

final class WorkflowApplicatorTest extends TestCase
{
    private const SUBJECT_CLASS = WorkflowApplicatorTestSubject::class;

    public function testApplyTransitionAndFlush(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $applicator = $this->applicator($definition, $em);
        $applicator->apply($subject, 'order_approval', 'approve');

        self::assertSame('approved', $subject->getStatus());
    }

    public function testApplyForSubjectUsesResolver(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $applicator = $this->applicator($definition);
        $applicator->applyForSubject($subject, 'approve');

        self::assertSame('approved', $subject->getStatus());
    }

    public function testApplyByContext(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $applicator = $this->applicator($definition);
        $applicator->applyByContext($subject, new WorkflowContext(), 'approve');

        self::assertSame('approved', $subject->getStatus());
    }

    public function testGetEnabledTransitionsAndMarking(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $applicator = $this->applicator($definition);

        self::assertSame(['approve'], $applicator->getEnabledTransitions($subject, 'order_approval'));
        self::assertSame('draft', $applicator->getMarking($subject, 'order_approval'));
        self::assertSame(['approve'], $applicator->getEnabledTransitionsForSubject($subject));
        self::assertSame('draft', $applicator->getMarkingForSubject($subject));
        self::assertSame('order_approval', $applicator->resolveForSubject($subject)->getSlug());
    }

    public function testGetEnabledTransitionsByContext(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $applicator = $this->applicator($definition);

        self::assertSame(['approve'], $applicator->getEnabledTransitionsByContext($subject, new WorkflowContext()));
    }

    public function testThrowsWhenTransitionNotEnabled(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('approved');

        $this->expectException(InvalidArgumentException::class);
        $this->applicator($definition)->apply($subject, 'order_approval', 'approve');
    }

    public function testThrowsWhenSubjectClassMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->applicator($this->orderDefinition())->apply(new stdClass(), 'order_approval', 'approve');
    }

    public function testThrowsWhenDefinitionMissing(): void
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn(null);

        $applicator = new WorkflowApplicator(
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
            $repository,
            new WorkflowResolver($repository),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(WorkflowNotFoundException::class);
        $applicator->apply(new WorkflowApplicatorTestSubject('draft'), 'missing', 'approve');
    }

    public function testThrowsWhenDefinitionDisabled(): void
    {
        $definition = $this->orderDefinition();
        $definition->setEnabled(false);

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($definition);

        $applicator = new WorkflowApplicator(
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
            $repository,
            new WorkflowResolver($repository),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(WorkflowNotFoundException::class);
        $applicator->apply(new WorkflowApplicatorTestSubject('draft'), 'order_approval', 'approve');
    }

    public function testGetMarkingReturnsEmptyStringWhenMarkingHasNoPlaces(): void
    {
        $definition = $this->orderDefinition();
        $subject    = new WorkflowApplicatorTestSubject('draft');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getMarking')->willReturn(new Marking([]));

        $registry = $this->createMock(WorkflowRegistryInterface::class);
        $registry->method('get')->with('order_approval')->willReturn($workflow);

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->with('order_approval')->willReturn($definition);

        $applicator = new WorkflowApplicator(
            $registry,
            $repository,
            new WorkflowResolver($repository),
            $this->createMock(EntityManagerInterface::class),
        );

        self::assertSame('', $applicator->getMarking($subject, 'order_approval'));
    }

    public function testFailedFlushResetsClosedEntityManagerSoNextRequestCanFlush(): void
    {
        $definition = $this->orderDefinition();
        $failure    = new RuntimeException('Unique constraint violation');
        $open       = true;
        $flushes    = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->expects(self::exactly(2))->method('flush')->willReturnCallback(static function () use (&$open, &$flushes, $failure): void {
            if (!$open) {
                throw new RuntimeException('EntityManager is closed');
            }

            if (++$flushes === 1) {
                $open = false;

                throw $failure;
            }
        });

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagers')->willReturn(['default' => $em]);
        $managerRegistry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
            $open = true;

            return $em;
        });

        $applicator = $this->applicator($definition, $em, $managerRegistry);

        try {
            $applicator->apply(new WorkflowApplicatorTestSubject('draft'), 'order_approval', 'approve');
            self::fail('The flush exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($em->isOpen());

        $secondRequestSubject = new WorkflowApplicatorTestSubject('draft');
        $applicator->apply($secondRequestSubject, 'order_approval', 'approve');

        self::assertSame('approved', $secondRequestSubject->getStatus());
    }

    public function testFailedFlushWithoutManagerRegistryRethrows(): void
    {
        $failure = new RuntimeException('Deadlock');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        $this->applicator($this->orderDefinition(), $em)->apply(new WorkflowApplicatorTestSubject('draft'), 'order_approval', 'approve');
    }

    private function orderDefinition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Order', 'order_approval', 'draft', self::SUBJECT_CLASS);
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('approved', null, 1));
        $definition->addTransition(new WorkflowTransition('approve', ['draft'], ['approved']));

        return $definition;
    }

    private function applicator(
        WorkflowDefinition $definition,
        ?EntityManagerInterface $em = null,
        ?ManagerRegistry $managerRegistry = null,
    ): WorkflowApplicator {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->with('order_approval')->willReturn($definition);
        $repository->method('findEnabledCandidates')->willReturn([$definition]);

        $em ??= $this->createMock(EntityManagerInterface::class);

        return new WorkflowApplicator(
            new DatabaseWorkflowRegistry($repository, new WorkflowDefinitionBuilder()),
            $repository,
            new WorkflowResolver($repository),
            $em,
            $managerRegistry,
        );
    }
}

final class WorkflowApplicatorTestSubject
{
    public function __construct(private string $status = 'draft')
    {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
