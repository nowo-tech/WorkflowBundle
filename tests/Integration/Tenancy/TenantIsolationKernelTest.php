<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\EventListener\TenantIsolationGuardSubscriber;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * Tenant isolation matrix of the runtime (registry, resolver, applicator, guard): a subject runs its tenant's
 * definition, else the global template, and never another tenant's, whatever way the workflow was obtained.
 */
final class TenantIsolationKernelTest extends TestCase
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
    public function aSubjectRunsItsTenantsDefinitionElseTheTemplateNeverAnotherTenants(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $global       = self::seedLeaveTemplate($kernel);
        $a            = new WorkflowTenant('org-a');
        $c            = new WorkflowTenant('org-c');
        self::versioning($kernel)->cloneTemplate('leave', $a, true);
        $draft = self::versioning($kernel)->createDraft('leave', $a);
        foreach ($draft->getTransitions() as $transition) {
            if ($transition->getName() === 'auto_approve') {
                $transition->setGuardExpression('days <= 5');
            }
        }
        self::repository($kernel)->save($draft);
        $aCurrent = self::versioning($kernel)->publishDraft('leave', $a);
        $cOwn     = self::seedLeaveTemplate($kernel, 'days <= 0', $c);
        // A tenant draft never runs.
        self::seedLeaveTemplate($kernel, 'days <= 9', new WorkflowTenant('org-d'), false);

        $expected = ['org-a' => $aCurrent, 'org-b' => $global, 'org-c' => $cOwn, 'org-d' => $global, '' => $global];
        foreach ($expected as $organization => $definition) {
            $subject  = new LeaveRequest('leave-' . $organization, $organization, 3);
            $workflow = self::registry($kernel)->forSubject($subject, 'leave');
            $metadata = $workflow->getMetadataStore()->getWorkflowMetadata();
            self::assertSame($definition->getId(), $metadata[DatabaseMetadataStore::DEFINITION_ID], 'registry for ' . $organization);
            self::assertContains($metadata[DatabaseMetadataStore::DEFINITION_TENANT], ['', $organization], 'never another tenant');

            $resolved = self::applicator($kernel)->resolveForSubject($subject);
            self::assertSame($definition->getId(), $resolved->getId(), 'resolver for ' . $organization);
            self::assertTrue($workflow->can($subject, 'submit'), 'its own workflow moves it: ' . $organization);
        }

        // Every other version is refused by the isolation guard, however the workflow was obtained.
        $versions = [$global, $aCurrent, $cOwn];
        foreach ($expected as $organization => $definition) {
            $subject = new LeaveRequest('leave-' . $organization, $organization, 3);
            foreach ($versions as $other) {
                if ($other->getId() === $definition->getId()) {
                    continue;
                }
                $workflow = self::registry($kernel)->forDefinition($other);
                self::assertFalse($workflow->can($subject, 'submit'), $organization . ' cannot run definition #' . $other->getId());
                $codes = [];
                foreach ($workflow->buildTransitionBlockerList($subject, 'submit') as $blocker) {
                    $codes[] = $blocker->getCode();
                }
                self::assertContains(TenantIsolationGuardSubscriber::CODE, $codes);
            }
        }

        // WorkflowRegistryInterface::get() returns the global template: it cannot move a customized tenant's subject.
        self::assertFalse(self::registry($kernel)->get('leave')->can(new LeaveRequest('leave-x', 'org-a', 3), 'submit'));
        self::assertTrue(self::registry($kernel)->get('leave')->can(new LeaveRequest('leave-x', 'org-b', 3), 'submit'));
    }

    #[Test]
    public function aPinOnAnotherTenantsDefinitionIsRefusedNeverFollowed(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $aOwn = self::seedLeaveTemplate($kernel, 'days <= 1', new WorkflowTenant('org-a'));
        // A tampered row: org-b's subject pinned on org-a's definition.
        self::service($kernel, SubjectPinStore::class, 'test.' . SubjectPinStore::class)->pin(new WorkflowTenant('org-b'), 'leave', new WorkflowSubjectRef('leave-b'), $aOwn);

        $this->expectException(WorkflowNotFoundException::class);
        self::registry($kernel)->forSubject(new LeaveRequest('leave-b', 'org-b', 1), 'leave');
    }

    #[Test]
    public function aDisabledTenantVersionDoesNotFallBackToTheTemplate(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $own = self::seedLeaveTemplate($kernel, 'days <= 1', new WorkflowTenant('org-a'));
        $own->setEnabled(false);
        self::repository($kernel)->save($own);

        try {
            self::registry($kernel)->forSubject(new LeaveRequest('leave-a', 'org-a', 1), 'leave');
            self::fail('The tenant opted out.');
        } catch (WorkflowNotFoundException) {
        }
        self::assertSame('', self::applicator($kernel)->resolveForSubject(new LeaveRequest('leave-b', 'org-b', 1))->getTenantKey());

        $this->expectException(WorkflowNotFoundException::class);
        self::applicator($kernel)->resolveForSubject(new LeaveRequest('leave-a', 'org-a', 1));
    }
}
