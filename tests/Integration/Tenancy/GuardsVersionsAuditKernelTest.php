<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use InvalidArgumentException;
use LogicException;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Event\WorkflowTransitionAppliedEvent;
use Nowo\WorkflowBundle\EventListener\TransitionGuardSubscriber;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowTransitionBlockedException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function sort;
use function sys_get_temp_dir;

/**
 * Per-transition guards (permission in the subject's context, conditions, automatic transitions), version pinning of
 * in-flight subjects and the after-commit audit event, on the Beacon-shaped tenancy kernel.
 */
final class GuardsVersionsAuditKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private ?TenancyTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    #[Test]
    public function aShortLeaveIsApprovedAutomaticallyAndALongOneWaitsForAManagerOfItsOrganization(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $short = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 2));
        $long  = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 3));

        self::applicator($kernel)->apply($short, 'leave', 'submit');
        self::assertSame('approved', $short->getStatus(), 'auto_approve: days <= 2');
        self::assertSame([], self::applicator($kernel)->applyAutomaticTransitions($short), 'idempotent');

        self::applicator($kernel)->apply($long, 'leave', 'submit');
        self::assertSame('submitted', $long->getStatus());

        foreach ([null, 'manager-org-b', 'admin-org-a'] as $actor) {
            $this->actAs($actor);
            try {
                self::applicator($kernel)->apply($long, 'leave', 'approve');
                self::fail('Only a manager of org-a may approve: ' . ($actor ?? 'nobody'));
            } catch (WorkflowTransitionBlockedException $exception) {
                self::assertContains(TransitionGuardSubscriber::CODE_PERMISSION, $exception->getBlockerCodes());
            }
        }

        $this->actAs('manager-org-a');
        self::applicator($kernel)->apply($long, 'leave', 'approve');
        self::assertSame('approved', $long->getStatus());

        $events = self::audit($kernel)->events;
        self::assertSame(['submit', 'auto_approve', 'submit', 'approve'], array_map(static fn (WorkflowTransitionAppliedEvent $e): string => $e->transition, $events));
        self::assertSame([false, true, false, false], array_map(static fn (WorkflowTransitionAppliedEvent $e): bool => $e->automatic, $events));
        self::assertSame('org-a', $events[3]->tenant);
        self::assertSame('leave-2', $events[3]->subjectRef);
        self::assertSame('manager-org-a', $events[3]->actorRef);
        self::assertNull($events[3]->definitionTenant);
        self::assertSame(['submitted'], $events[3]->from);
        self::assertSame(['approved'], $events[3]->to);
    }

    #[Test]
    public function automaticTransitionsStopOnALoopAndAfterTheStepLimit(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $loop         = self::seedLeaveTemplate($kernel, 'days >= 0');
        // approved → submitted is automatic too: a loop the applicator must stop.
        $loop->addTransition((new WorkflowTransition('reopen', ['approved'], ['submitted']))->setAutomatic(true));
        self::repository($kernel)->save($loop);
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-3', 'org-a', 1));

        self::applicator($kernel)->apply($leave, 'leave', 'submit');

        self::assertSame('submitted', $leave->getStatus(), 'stopped when the marking repeats');
        $logger = self::service($kernel, RecordingLogger::class, 'logger');
        self::assertStringContainsString('loop back', $logger->records[0]['message'] ?? '');

        $chain = new WorkflowDefinition('Chain', 'chain', 'requested', LeaveRequest::class);
        $chain->addPlace(new WorkflowPlace('requested'));
        $chain->addTransition(new WorkflowTransition('start', ['requested'], ['s0']));
        for ($i = 0; $i < 8; ++$i) {
            $chain->addPlace(new WorkflowPlace('s' . $i, null, $i + 1));
            if ($i > 0) {
                $chain->addTransition((new WorkflowTransition('step' . $i, ['s' . ($i - 1)], ['s' . $i]))->setAutomatic(true));
            }
        }
        self::repository($kernel)->save($chain);
        $other = self::persistLeave($kernel, new LeaveRequest('leave-4', 'org-a', 1));
        self::applicator($kernel)->apply($other, 'chain', 'start');
        self::assertSame('s5', $other->getStatus(), 'guards.max_automatic_transitions: 5');
        self::assertStringContainsString('stopped after', $logger->records[1]['message'] ?? '');
    }

    #[Test]
    public function inFlightSubjectsKeepTheVersionTheyStartedWith(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel, 'days <= 0');
        $a  = new WorkflowTenant('org-a');
        $v1 = self::versioning($kernel)->cloneTemplate('leave', $a, true);
        $x  = self::persistLeave($kernel, new LeaveRequest('leave-x', 'org-a', 5));
        self::applicator($kernel)->apply($x, 'leave', 'submit');

        $draft = self::versioning($kernel)->createDraft('leave', $a);
        self::assertSame($draft->getId(), self::versioning($kernel)->createDraft('leave', $a)->getId(), 'one open draft');
        foreach ($draft->getTransitions() as $transition) {
            if ($transition->getName() === 'approve') {
                $draft->removeTransition($transition);
            }
        }
        $draft->addTransition((new WorkflowTransition('approve_v2', ['submitted'], ['approved']))->setGuardPermission('leave.approve'));
        self::repository($kernel)->save($draft);
        $v2 = self::versioning($kernel)->publishDraft('leave', $a);
        self::assertSame(2, $v2->getVersion());

        $this->actAs('manager-org-a');
        $y = self::persistLeave($kernel, new LeaveRequest('leave-y', 'org-a', 5));
        self::applicator($kernel)->apply($y, 'leave', 'submit');
        $enabled = self::applicator($kernel)->getEnabledTransitions($y, 'leave');
        sort($enabled);
        self::assertSame(['approve_v2', 'reject'], $enabled, 'a new subject starts with v2');
        self::assertSame(['approve', 'reject'], self::applicator($kernel)->getEnabledTransitions($x, 'leave'), 'x keeps v1');
        self::applicator($kernel)->apply($x, 'leave', 'approve');
        self::assertSame('approved', $x->getStatus());

        try {
            self::applicator($kernel)->apply($y, 'leave', 'approve');
            self::fail('v2 has no approve transition.');
        } catch (InvalidArgumentException) {
        }

        $pins = self::hostManager($kernel)->getRepository(WorkflowSubjectPin::class)->findBy([], ['subjectRef' => 'ASC']);
        self::assertSame([[$v1->getId(), 'leave-x'], [$v2->getId(), 'leave-y']], array_map(static fn (WorkflowSubjectPin $p): array => [$p->getDefinition()->getId(), $p->getSubjectRef()], $pins));

        // Owner decision 2026-11-12: a definition running subjects are pinned to cannot be deleted.
        try {
            self::versioning($kernel)->delete($v2);
            self::fail('Pinned subjects block the deletion.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(2, $exception->pinnedSubjects);
            self::assertSame('leave', $exception->workflowSlug);
        }
        $draft = self::versioning($kernel)->createDraft('leave', $a);
        self::versioning($kernel)->delete($draft);
        self::assertNull(self::repository($kernel)->findDraft('leave', $a), 'a draft is never pinned: deleted');

        // Once the subjects are forgotten, the definition can go: they follow the global template again.
        self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-x'), $a);
        self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-y'), $a);
        self::versioning($kernel)->delete($v2);
        self::assertNull(self::repository($kernel)->findCurrent('leave', $a));
        self::assertSame('', self::registry($kernel)->forSubject($x, 'leave')->getMetadataStore()->getWorkflowMetadata()['nowo_definition_tenant']);
    }

    #[Test]
    public function theAuditEventFollowsTheCommitAndNeverARollback(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel, 'days <= 0');
        $connection = self::hostManager($kernel)->getConnection();
        $leave      = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));

        $connection->beginTransaction();
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        self::assertSame([], self::audit($kernel)->events, 'held inside the host transaction');
        $connection->rollBack();
        self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class)->onBoundary();
        self::assertSame([], self::audit($kernel)->events, 'never for a rollback');

        $leave = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 5));
        $connection->beginTransaction();
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        self::assertSame([], self::audit($kernel)->events);
        $connection->commit();
        self::hostManager($kernel)->flush();
        self::assertSame([], self::audit($kernel)->events, 'not from a Doctrine postFlush (audit NO M4)');
        self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class)->onBoundary();
        self::assertCount(1, self::audit($kernel)->events, 'after the outermost commit, at the end of the request');

        $leave = self::persistLeave($kernel, new LeaveRequest('leave-3', 'org-a', 5));
        $connection->beginTransaction();
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class)->onBoundary();
        $connection->rollBack();
        self::assertCount(1, self::audit($kernel)->events, 'a transaction still open at the end of the unit: dropped (audit NO L1)');
    }

    #[Test]
    public function withoutFlushTheEventWaitsForTheHostsCommitOrIsDropped(): void
    {
        $this->kernel = $kernel = self::boot('tenancy', false);
        self::seedLeaveTemplate($kernel, 'days <= 0');
        $dispatcher = self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class);
        $leave      = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));

        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        self::assertSame([], self::audit($kernel)->events, 'the marking is not persisted yet');
        self::hostManager($kernel)->flush();
        $dispatcher->onBoundary();
        self::assertCount(1, self::audit($kernel)->events, 'the host flush committed it');

        $other = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 5));
        self::applicator($kernel)->apply($other, 'leave', 'submit');
        self::assertSame(1, $dispatcher->countPending());
        $dispatcher->onBoundary();
        self::assertSame(0, $dispatcher->countPending(), 'never persisted: dropped');
        self::assertCount(1, self::audit($kernel)->events);
    }

    private function actAs(?string $user): void
    {
        self::service($this->kernel ?? throw new LogicException(), TokenStorageInterface::class, 'security.token_storage')
            ->setToken($user === null ? null : new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_USER']), 'main', ['ROLE_USER']));
    }
}
