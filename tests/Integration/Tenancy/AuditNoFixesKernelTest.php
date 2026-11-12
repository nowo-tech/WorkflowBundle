<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Exception\WorkflowPinConflictException;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function str_replace;
use function sys_get_temp_dir;

/**
 * Audit NO fixes on a kernel whose key columns compare case-insensitively (SQLite NOCASE, the behaviour of a MySQL
 * `_ci` collation): `acme` and `Acme` stay different tenants (M1); tenant scopes cannot rebind a definition and use
 * strict names (M2); a concurrent pin never breaks the transaction (M3); a published definition with pinned subjects
 * cannot be deleted (owner decision 2026-11-12).
 */
final class AuditNoFixesKernelTest extends TestCase
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
    public function aCaseInsensitiveCollationNeverMixesTenants(): void
    {
        $this->kernel = $kernel = $this->caseInsensitiveKernel();
        $global       = self::seedLeaveTemplate($kernel);
        $own          = self::seedLeaveTemplate($kernel, 'days <= 1', new WorkflowTenant('acme'));
        $own->setName('Leave acme only');
        self::repository($kernel)->save($own);

        $metadata = self::registry($kernel)->forSubject(new LeaveRequest('leave-1', 'Acme', 3), 'leave')->getMetadataStore()->getWorkflowMetadata();
        self::assertSame($global->getId(), $metadata[DatabaseMetadataStore::DEFINITION_ID], 'Acme runs the template, never acme\'s copy');
        self::assertSame($own->getId(), self::registry($kernel)->forSubject(new LeaveRequest('leave-1', 'acme', 3), 'leave')->getMetadataStore()->getWorkflowMetadata()[DatabaseMetadataStore::DEFINITION_ID]);
        self::assertNull(self::repository($kernel)->findCurrent('leave', new WorkflowTenant('ACME')));
        self::assertFalse(self::repository($kernel)->existsInScope('leave', new WorkflowTenant('Acme')));
        self::assertSame([], self::repository($kernel)->findVersions('LEAVE', new WorkflowTenant('acme')));

        $globalDraft = self::versioning($kernel)->createDraft('leave', null);
        $globalDraft->setName('Global draft only');
        self::repository($kernel)->save($globalDraft);

        $list = (string) self::send($kernel, self::ui('Acme', '/definitions'), 'admin-Acme')->getContent();
        self::assertStringNotContainsString('Leave acme only', $list);
        self::assertStringNotContainsString('Global draft only', $list, 'a tenant never lists a global draft');
        self::assertSame(404, self::send($kernel, self::ui('Acme', '/definitions/' . $own->getId()), 'admin-Acme')->getStatusCode());

        // Pins: leave-1 of acme is not LEAVE-1 of acme. The insert of LEAVE-1 collides with leave-1 in the folded
        // unique index: it runs in a savepoint, the winning pin cannot be read for LEAVE-1, so the transition is not
        // applied with a guessed version (audit NO-v N2) and the host transaction goes on.
        $connection = self::hostManager($kernel)->getConnection();
        $lower      = self::persistLeave($kernel, new LeaveRequest('leave-1', 'acme', 3));
        self::applicator($kernel)->apply($lower, 'leave', 'submit');
        $upper = self::persistLeave($kernel, new LeaveRequest('LEAVE-1', 'acme', 3));
        $connection->beginTransaction();
        try {
            self::applicator($kernel)->apply($upper, 'leave', 'submit');
            self::fail('The concurrent pin cannot be read: retry.');
        } catch (WorkflowPinConflictException) {
        }
        $connection->executeStatement("UPDATE host_leave_request SET type = 'sick' WHERE uuid = 'LEAVE-1'");
        $connection->commit();
        self::assertSame('requested', $upper->getStatus());
        self::assertSame(0, self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('LEAVE-1'), new WorkflowTenant('acme')));
        self::assertSame(1, self::versioning($kernel)->forgetSubject(new WorkflowSubjectRef('leave-1'), new WorkflowTenant('acme')));

        // A slug taken by a case variant of the scope only collides in the database: the form says so (L3).
        $taken = self::send($kernel, self::ui('ACME', '/definitions/new'), 'admin-ACME', 'POST', ['workflow_definition_form' => [
            'name'            => 'Leave',
            'slug'            => 'leave',
            'type'            => 'state_machine',
            'initialPlace'    => 'requested',
            'subjectClass'    => LeaveRequest::class,
            'markingProperty' => 'status',
            'priority'        => '0',
            '_token'          => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(422, $taken->getStatusCode());
    }

    #[Test]
    public function aTenantCannotRebindADefinitionAndNamesAreIdentifiers(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $draft = self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'));
        $id    = (int) $draft->getId();

        $general = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/general'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'name'            => 'Renamed',
            'type'            => 'workflow',
            'initialPlace'    => 'requested',
            'subjectClass'    => 'App\\Billing\\Invoice',
            'markingProperty' => 'paid',
            'enabled'         => '1',
            'priority'        => '0',
            '_token'          => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(302, $general->getStatusCode());
        $saved = self::repository($kernel)->find($id);
        self::assertNotNull($saved);
        self::assertSame(['Renamed', LeaveRequest::class, 'status', 'state_machine'], [$saved->getName(), $saved->getSubjectClass(), $saved->getMarkingProperty(), $saved->getType()->value]);

        $places = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/places'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'places' => [['name' => 'Bad Name', 'sortOrder' => '0']],
            '_token' => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(422, $places->getStatusCode());
    }

    #[Test]
    public function aPublishedDefinitionWithPinnedSubjectsCannotBeDeletedFromTheUi(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel, 'days <= 0');
        $own   = self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'), true);
        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));
        self::applicator($kernel)->apply($leave, 'leave', 'submit');
        $id = (int) $own->getId();

        $response = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/delete'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'delete' . $id)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/definitions/' . $id, (string) $response->headers->get('Location'));
        self::assertNotNull(self::repository($kernel)->find($id), 'kept');
    }

    private function caseInsensitiveKernel(): TenancyTestKernel
    {
        $kernel = new TenancyTestKernel('tenancy');
        $kernel->boot();
        $doctrine = self::service($kernel, ManagerRegistry::class, 'doctrine');
        $manager  = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();
        foreach ((new SchemaTool($manager))->getCreateSchemaSql($manager->getMetadataFactory()->getAllMetadata()) as $sql) {
            $connection->executeStatement(str_replace(
                ["tenant_key VARCHAR(128) DEFAULT '' NOT NULL", 'tenant_key VARCHAR(128) NOT NULL', 'subject_ref VARCHAR(128) NOT NULL', 'slug VARCHAR(128) NOT NULL'],
                ["tenant_key VARCHAR(128) DEFAULT '' NOT NULL COLLATE NOCASE", 'tenant_key VARCHAR(128) NOT NULL COLLATE NOCASE', 'subject_ref VARCHAR(128) NOT NULL COLLATE NOCASE', 'slug VARCHAR(128) NOT NULL COLLATE NOCASE'],
                $sql,
            ));
        }

        return $kernel;
    }
}
