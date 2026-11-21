<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Doctrine\WorkflowEntityManager;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Relations\Employee;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Relations\ExpenseClaim;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 2.0 (private entity manager only): a host whose entities point at each other (an expense claim with a ManyToOne to
 * its requester and approver) and at a bundle row (a ManyToOne to the definition version, set with the host manager's
 * `getReference()`). Apply, automatic history (the after-commit audit event), pin / repin / forget, admin-UI versioning
 * and the version port all run with those host entities managed by the application's manager: the bundle never
 * attaches a host entity to a row of its private manager (no "A new entity was found through the relationship"),
 * never flushes, clears or resets the host manager unless `flush_after_apply: true` asks it to flush, and keeps every
 * host relation intact.
 */
final class HostRelationsKernelTest extends TestCase
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

    /**
     * @return iterable<string, array{bool}>
     */
    public static function flushModes(): iterable
    {
        yield 'flush_after_apply: false (2.0 default), the host flushes' => [false];
        yield 'flush_after_apply: true (explicit), the bundle flushes the host manager' => [true];
    }

    #[Test]
    #[DataProvider('flushModes')]
    public function hostEntitiesWithRelationsRunEveryFlowOnThePrivateManager(bool $flushAfterApply): void
    {
        $this->kernel = $kernel = self::boot('tenancy-relations', $flushAfterApply);
        $host         = self::hostManager($kernel);
        $orgA         = new WorkflowTenant('org-a');
        $manager      = self::versionManager($kernel);

        // The global template and its tenant clone, written through the private manager.
        $this->seedClaimTemplate($kernel);
        $clone = $manager->cloneTemplate('claim', $orgA, publish: true);

        // Host entities: two employees and a claim pointing at them and (by reference) at the clone.
        $alice = new Employee('alice');
        $bob   = new Employee('bob');
        $claim = new ExpenseClaim('claim-1', 'org-a', $alice);
        $host->persist($alice);
        $host->persist($bob);
        $host->persist($claim);
        $claim->setWorkflowVersion($host->getReference(WorkflowDefinition::class, $clone->ref->id));
        $host->flush();

        // Apply: the marking changes; the approver (a host relation) is changed by the host around apply().
        $claim->setApprover($bob);
        $result = $this->runtime($kernel)->apply($claim, 'claim', 'submit');
        self::assertTrue($result->applied);
        self::assertSame('org-a', $result->marking->definitionTenant);
        if (!$flushAfterApply) {
            self::assertSame('requested', $this->claimRow($host)['status'], '2.0 default: nothing written by the bundle');
            $host->flush();
        }
        self::assertSame(['status' => 'submitted', 'requester_id' => $alice->getId(), 'approver_id' => $bob->getId(), 'workflow_version_id' => $clone->ref->id], $this->claimRow($host));
        self::assertSame([], $host->getUnitOfWork()->getScheduledEntityInsertions(), 'nothing of the bundle was put in the host manager');
        foreach ($this->privateIdentityMap($kernel) as $entity) {
            self::assertStringStartsWith('Nowo\\WorkflowBundle\\Entity\\', $entity::class, 'the private manager holds bundle rows only');
        }

        // History: the after-commit audit event carries ids only. It is dispatched at the next safe point after the
        // commit that persisted the subject: right after the bundle's flush (true), at the end of the request, command
        // or message otherwise (here, outside a request, the safe point is reached explicitly).
        self::service($kernel, AfterCommitDispatcher::class, 'test.' . AfterCommitDispatcher::class)->dispatchPending();
        $events = self::audit($kernel)->events;
        self::assertCount(1, $events);
        self::assertSame('claim-1', $events[0]->subjectRef);
        self::assertSame('submit', $events[0]->transition);

        // The pin is a row of the private manager's connection, by id: the host entities are never in it.
        $pins = $this->pins($kernel);
        self::assertCount(1, $pins);
        self::assertSame('claim-1', $pins[0]->getSubjectRef());
        self::assertSame($clone->ref->id, $pins[0]->getDefinition()->getId());

        // Admin-UI versioning (draft + publish of the clone) through the private manager, the host entities untouched.
        $draftResponse = self::send($kernel, self::ui('org-a', '/definitions/' . $clone->ref->id . '/draft'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'draft' . $clone->ref->id)]);
        self::assertSame(302, $draftResponse->getStatusCode());
        $draft = $manager->draftVersion('claim', $orgA);
        self::assertNotNull($draft);
        $publishResponse = self::send($kernel, self::ui('org-a', '/definitions/' . $draft->ref->id . '/publish'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'publish' . $draft->ref->id)]);
        self::assertSame(302, $publishResponse->getStatusCode());
        $current = $manager->currentVersion('claim', $orgA);
        self::assertNotNull($current);
        self::assertSame($draft->ref->id, $current->ref->id);

        // Repin to the new version; the host records it by reference again and flushes its own manager.
        $repinned = $this->runtime($kernel)->pin($claim, 'claim', WorkflowPinMode::Repin);
        self::assertSame($current->ref->version, $repinned->definitionVersion);
        $claim->setWorkflowVersion($host->getReference(WorkflowDefinition::class, $current->ref->id));
        $host->flush();
        self::assertSame($current->ref->id, $this->claimRow($host)['workflow_version_id']);

        // Owner rule (2026-11-12): the pinned version is not deleted; forget the subject (unpin), drop the host's own
        // reference, then the delete goes through. The host relations to the employees stay.
        try {
            $manager->delete($current->ref);
            self::fail('A pinned version is not deleted.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(1, $exception->pinnedSubjects);
        }
        self::assertSame(1, $manager->forgetSubject(new WorkflowSubjectRef('claim-1'), $orgA));
        self::assertSame([], $this->pins($kernel));
        $claim->setWorkflowVersion(null);
        $host->flush();
        $manager->delete($current->ref);
        self::assertNull($manager->currentVersion('claim', $orgA));
        self::assertSame(['status' => 'submitted', 'requester_id' => $alice->getId(), 'approver_id' => $bob->getId(), 'workflow_version_id' => null], $this->claimRow($host));
        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($claim), 'the host manager was never cleared or reset');
    }

    /**
     * The global "claim" template: requested → submitted → approved.
     */
    private function seedClaimTemplate(TenancyTestKernel $kernel): void
    {
        $definition = new WorkflowDefinition('Expense claim', 'claim', 'requested', ExpenseClaim::class);
        foreach (['requested', 'submitted', 'approved'] as $index => $place) {
            $definition->addPlace(new WorkflowPlace($place, null, $index));
        }
        $definition->addTransition(new WorkflowTransition('submit', ['requested'], ['submitted']));
        $definition->addTransition(new WorkflowTransition('approve', ['submitted'], ['approved']));
        self::repository($kernel)->save($definition);
    }

    private function runtime(TenancyTestKernel $kernel): WorkflowRuntimeInterface
    {
        return self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class);
    }

    /**
     * @return array{status: string, requester_id: int|null, approver_id: int|null, workflow_version_id: int|null}
     */
    private function claimRow(EntityManagerInterface $host): array
    {
        /** @var array{status: string, requester_id: int|string|null, approver_id: int|string|null, workflow_version_id: int|string|null} $row */
        $row = $host->getConnection()->fetchAssociative('SELECT status, requester_id, approver_id, workflow_version_id FROM host_expense_claim');

        return [
            'status'              => $row['status'],
            'requester_id'        => $row['requester_id'] === null ? null : (int) $row['requester_id'],
            'approver_id'         => $row['approver_id'] === null ? null : (int) $row['approver_id'],
            'workflow_version_id' => $row['workflow_version_id'] === null ? null : (int) $row['workflow_version_id'],
        ];
    }

    /**
     * @return list<WorkflowSubjectPin>
     */
    private function pins(TenancyTestKernel $kernel): array
    {
        $private = self::service($kernel, WorkflowEntityManager::class, 'test.' . WorkflowEntityManager::class);
        $private->clear();

        $pins = [];
        foreach ($private->getRepository(WorkflowSubjectPin::class)->findAll() as $pin) {
            self::assertInstanceOf(WorkflowSubjectPin::class, $pin);
            $pins[] = $pin;
        }

        return $pins;
    }

    /**
     * @return list<object>
     */
    private function privateIdentityMap(TenancyTestKernel $kernel): array
    {
        $entities = [];
        foreach (self::service($kernel, WorkflowEntityManager::class, 'test.' . WorkflowEntityManager::class)->getUnitOfWork()->getIdentityMap() as $map) {
            foreach ($map as $entity) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }
}
