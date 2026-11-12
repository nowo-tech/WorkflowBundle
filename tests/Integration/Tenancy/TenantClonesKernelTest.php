<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use ArrayObject;
use InvalidArgumentException;
use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRuntimeInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Exception\WorkflowStructureLockedException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\LeaveRequestWithoutReference;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 1.16 (Beacon): retiring a tenant clone, locking a clone's structure, and pinning a subject explicitly. Requests go
 * straight to `http_kernel->handle()` / `terminate()` (no services resetter), as in FrankenPHP worker mode.
 */
final class TenantClonesKernelTest extends TestCase
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
    public function aRetiredCloneRunsTheTemplateForNewSubjectsAndKeepsItsPinnedSubjects(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $orgA         = new WorkflowTenant('org-a');
        $template     = self::seedLeaveTemplate($kernel);
        $clone        = self::versioning($kernel)->cloneTemplate('leave', $orgA, publish: true);
        $runtime      = $this->runtime($kernel);

        $inFlight = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 5));
        self::assertTrue($runtime->apply($inFlight, 'leave', 'submit')->applied);
        self::assertSame('org-a', $runtime->marking($inFlight, 'leave')->definitionTenant);

        self::assertNull(self::repository($kernel)->findRetired('leave', $orgA), 'nothing retired yet');
        self::assertNull(self::repository($kernel)->findVersion('leave', $orgA, 9));
        self::assertSame($clone->getId(), self::repository($kernel)->findVersion('leave', $orgA, 1)?->getId());

        $retired = self::versioning($kernel)->retire($clone);
        self::assertSame($clone->getId(), $retired->getId());
        self::assertTrue($retired->isRetired());
        self::assertNull(self::repository($kernel)->findCurrent('leave', $orgA), 'a retired clone has no current version');
        self::assertSame($retired->getId(), self::repository($kernel)->findRetired('leave', $orgA)?->getId());
        self::assertSame($template->getId(), self::repository($kernel)->findForTenant('leave', $orgA)?->getId());

        // New subjects of the tenant run the template, from every entry point.
        $fresh = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 5));
        self::assertNull($runtime->marking($fresh, 'leave')->definitionTenant);
        self::assertSame($template->getId(), self::applicator($kernel)->resolveForSubject($fresh)->getId());
        self::assertTrue($runtime->apply($fresh, 'leave', 'submit')->applied);
        self::assertNull($runtime->marking($fresh, 'leave')->definitionTenant, 'pinned to the template');

        // The subject already running keeps its pinned (retired) version; the isolation guard lets it move.
        self::assertSame('org-a', $runtime->marking($inFlight, 'leave')->definitionTenant);
        self::assertSame([], $runtime->enabledTransitions($inFlight, 'leave'), 'approve / reject need the manager permission');

        // Owner decision 2026-11-12: still not deletable while pins exist.
        try {
            self::versioning($kernel)->delete($retired);
            self::fail('Pinned subjects block the delete.');
        } catch (WorkflowDefinitionInUseException $exception) {
            self::assertSame(1, $exception->pinnedSubjects);
        }

        // Reinstate: new subjects run the clone again.
        $back = self::versioning($kernel)->reinstate($retired);
        self::assertTrue($back->isPublished());
        $later = self::persistLeave($kernel, new LeaveRequest('leave-3', 'org-a', 5));
        self::assertSame('org-a', $runtime->marking($later, 'leave')->definitionTenant);

        // Retired again, then customized again from the template: the copy is the clone's next version.
        self::versioning($kernel)->retire($back);
        self::assertTrue(self::versioning($kernel)->isRetiredScope('leave', $orgA));
        $again = self::versioning($kernel)->cloneTemplate('leave', $orgA);
        self::assertSame(2, $again->getVersion());
        self::assertTrue($again->isDraft());
        self::assertSame(1, $again->getTemplateVersion());
        self::assertFalse(self::versioning($kernel)->isRetiredScope('leave', $orgA));
        self::versioning($kernel)->publish($again);
        self::assertSame($again->getId(), self::repository($kernel)->findCurrent('leave', $orgA)?->getId());
        self::assertSame('org-a', $runtime->marking(self::persistLeave($kernel, new LeaveRequest('leave-4', 'org-a', 5)), 'leave')->definitionTenant);
        self::assertSame('org-a', $runtime->marking($inFlight, 'leave')->definitionTenant);
        self::assertSame(1, $runtime->marking($inFlight, 'leave')->definitionVersion, 'still the retired v1');

        try {
            self::versioning($kernel)->cloneTemplate('leave', $orgA);
            self::fail('An active clone is not cloned again.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already has a workflow', $exception->getMessage());
        }
    }

    #[Test]
    public function aCloneIsRetiredOnlyWhenTheTemplateCanTakeOver(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $orgA         = new WorkflowTenant('org-a');
        $template     = self::seedLeaveTemplate($kernel);
        $draft        = self::versioning($kernel)->cloneTemplate('leave', $orgA);

        $refusals = [
            'global'     => [$template, 'is a global template'],
            'only draft' => [$draft, 'has no published version'],
        ];
        foreach ($refusals as $case => [$definition, $message]) {
            try {
                self::versioning($kernel)->retire($definition);
                self::fail($case);
            } catch (LogicException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }

        $published = self::versioning($kernel)->publish($draft);
        $template->setEnabled(false);
        self::repository($kernel)->save($template);
        try {
            self::versioning($kernel)->retire($published);
            self::fail('A disabled template cannot take over.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('no enabled global template', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no retired version');
        self::versioning($kernel)->reinstate($published);
    }

    #[Test]
    public function retireAndReinstateThroughTheUi(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $orgA         = new WorkflowTenant('org-a');
        $template     = self::seedLeaveTemplate($kernel);
        $clone        = self::versioning($kernel)->cloneTemplate('leave', $orgA, publish: true);
        $id           = (int) $clone->getId();
        $g            = (int) $template->getId();

        $show = self::send($kernel, self::ui('org-a', '/definitions/' . $id), 'admin-org-a');
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('/definitions/' . $id . '/retire', (string) $show->getContent());
        self::assertStringNotContainsString('/definitions/' . $id . '/reinstate', (string) $show->getContent());
        $list = self::send($kernel, self::ui('org-a', '/definitions'), 'admin-org-a');
        self::assertStringContainsString('/definitions/' . $id . '/retire', (string) $list->getContent(), 'row action');

        $matrix = [
            [self::ui('org-a', '/definitions/' . $id . '/retire'), 'viewer-org-a', ['_token' => self::csrf($kernel, 'retire' . $id)], 403],
            [self::ui('org-a', '/definitions/' . $id . '/retire'), 'admin-org-a', ['_token' => 'wrong'], 403],
            [self::ui('org-b', '/definitions/' . $id . '/retire'), 'admin-org-b', ['_token' => self::csrf($kernel, 'retire' . $id)], 404],
            [self::ui('org-a', '/definitions/' . $g . '/retire'), 'admin-org-a', ['_token' => self::csrf($kernel, 'retire' . $g)], 403],
            [self::ui('global', '/definitions/' . $g . '/retire'), 'root', ['_token' => self::csrf($kernel, 'retire' . $g)], 404],
            [self::ui('org-a', '/definitions/' . $id . '/reinstate'), 'admin-org-a', ['_token' => self::csrf($kernel, 'reinstate' . $id)], 404],
        ];
        foreach ($matrix as [$path, $user, $parameters, $status]) {
            self::assertSame($status, self::send($kernel, $path, $user, 'POST', $parameters)->getStatusCode(), $path . ' as ' . $user);
        }

        $retire = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/retire'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'retire' . $id)]);
        self::assertSame(302, $retire->getStatusCode());
        self::assertStringEndsWith('/organizations/org-a/settings/workflow/definitions/' . $id, (string) $retire->headers->get('Location'));
        self::assertTrue(self::repository($kernel)->find($id)?->isRetired());
        self::assertSame(404, self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/retire'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'retire' . $id)])->getStatusCode(), 'only a published version');

        $list = (string) self::send($kernel, self::ui('org-a', '/definitions'), 'admin-org-a')->getContent();
        self::assertStringContainsString('workflow-badge-retired', $list);
        self::assertStringContainsString('workflow-badge-template', $list, 'the template runs new subjects again: listed');
        $show = (string) self::send($kernel, self::ui('org-a', '/definitions/' . $id), 'admin-org-a')->getContent();
        self::assertStringContainsString('/definitions/' . $id . '/reinstate', $show);
        self::assertStringNotContainsString('/definitions/' . $id . '/retire', $show);
        self::assertSame(200, self::send($kernel, self::ui('org-a', '/definitions/by-slug/leave'), 'admin-org-a')->getStatusCode());

        $reinstate = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/reinstate'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'reinstate' . $id)]);
        self::assertSame(302, $reinstate->getStatusCode());
        self::assertTrue(self::repository($kernel)->find($id)?->isPublished());

        // No template to fall back to: refused with a message, nothing changes (the private manager was dropped at the
        // end of each request: load the template again).
        $template = self::repository($kernel)->find($g);
        self::assertInstanceOf(WorkflowDefinition::class, $template);
        $template->setEnabled(false);
        self::repository($kernel)->save($template);
        $refused = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/retire'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'retire' . $id)]);
        self::assertSame(302, $refused->getStatusCode());
        self::assertTrue(self::repository($kernel)->find($id)?->isPublished());
    }

    #[Test]
    public function aLockedCloneOnlyEditsGuardsAndConditions(): void
    {
        $this->kernel = $kernel = self::boot('tenancy-locked');
        $orgA         = new WorkflowTenant('org-a');
        self::seedLeaveTemplate($kernel);
        $draft = self::versioning($kernel)->cloneTemplate('leave', $orgA);
        $id    = (int) $draft->getId();

        foreach (['places', 'transitions', 'general'] as $section) {
            $page = (string) self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/' . $section), 'admin-org-a')->getContent();
            self::assertStringContainsString('workflow-structure-locked', $page, $section);
            self::assertStringNotContainsString('data-collection-add', $page, $section);
        }
        $matchRules = (string) self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/match-rules'), 'admin-org-a')->getContent();
        self::assertStringContainsString('data-collection-add', $matchRules, 'match rules are not structure');

        // The browser sends a renamed place and a new one: disabled fields and a fixed collection ignore them.
        $places = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/places'), 'admin-org-a', 'POST', ['workflow_definition_form' => [
            'places' => [['name' => 'hacked', 'label' => 'x', 'sortOrder' => '0']],
            '_token' => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(302, $places->getStatusCode());
        $reloaded = self::repository($kernel)->find($id);
        self::assertInstanceOf(WorkflowDefinition::class, $reloaded);
        self::assertSame(['requested', 'submitted', 'approved', 'rejected'], $reloaded->getPlaceNames());

        // A transition's guard changes; its places, name and the list of transitions do not.
        $transitions = [];
        foreach ($reloaded->getTransitions() as $index => $transition) {
            $transitions[$index] = ['name' => 'renamed_' . $index, 'fromPlaces' => ['approved'], 'toPlaces' => ['rejected'], 'guardExpression' => $transition->getName() === 'auto_approve' ? 'days <= 3' : '', 'guardPermission' => $transition->getGuardPermission() ?? ''];
        }
        $saved = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/edit/transitions'), 'admin-org-a', 'POST', ['workflow_definition_form' => ['transitions' => $transitions, '_token' => self::csrf($kernel, 'workflow_definition_form')]]);
        self::assertSame(302, $saved->getStatusCode());
        $reloaded = self::repository($kernel)->find($id);
        self::assertInstanceOf(WorkflowDefinition::class, $reloaded);
        $template = self::repository($kernel)->findCurrent('leave', null);
        self::assertInstanceOf(WorkflowDefinition::class, $template);
        self::assertSame($template->structure(), $reloaded->structure());
        $guards = [];
        foreach ($reloaded->getTransitions() as $transition) {
            $guards[$transition->getName()] = $transition->getGuardExpression();
        }
        self::assertSame('days <= 3', $guards['auto_approve']);

        // A structure changed another way (host code, an older draft) is refused when published.
        $reloaded->addPlace(new WorkflowPlace('escalated', null, 9));
        self::repository($kernel)->save($reloaded);
        $publish = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/publish'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'publish' . $id)]);
        self::assertSame(302, $publish->getStatusCode());
        self::assertTrue(self::repository($kernel)->find($id)?->isDraft(), 'not published');
        try {
            self::versioning($kernel)->publish($reloaded);
            self::fail('Locked.');
        } catch (WorkflowStructureLockedException $exception) {
            self::assertSame('leave', $exception->workflowSlug);
        }

        // A tenant cannot shadow the template with its own definition under the same slug either.
        $new = self::send($kernel, self::ui('org-b', '/definitions/new'), 'admin-org-b', 'POST', ['workflow_definition_form' => [
            'name'            => 'Leave', 'slug' => 'leave', 'type' => 'state_machine', 'initialPlace' => 'requested', 'subjectClass' => LeaveRequest::class,
            'markingProperty' => 'status', 'enabled' => '1', 'priority' => '0', '_token' => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(422, $new->getStatusCode());
        self::assertNull(self::repository($kernel)->findDraft('leave', new WorkflowTenant('org-b')));
        $other = self::send($kernel, self::ui('org-b', '/definitions/new'), 'admin-org-b', 'POST', ['workflow_definition_form' => [
            'name'            => 'Expenses', 'slug' => 'expenses', 'type' => 'state_machine', 'initialPlace' => 'draft', 'subjectClass' => LeaveRequest::class,
            'markingProperty' => 'status', 'enabled' => '1', 'priority' => '0', '_token' => self::csrf($kernel, 'workflow_definition_form'),
        ]]);
        self::assertSame(302, $other->getStatusCode(), 'a slug without a template is the tenant\'s own');
        $expenses = self::repository($kernel)->findDraft('expenses', new WorkflowTenant('org-b'));
        self::assertInstanceOf(WorkflowDefinition::class, $expenses);
        self::assertStringNotContainsString('workflow-structure-locked', (string) self::send($kernel, self::ui('org-b', '/definitions/' . $expenses->getId() . '/edit/places'), 'admin-org-b')->getContent());
    }

    #[Test]
    public function pinPinsWithoutApplyingAnythingAndIsIdempotentAndTenantChecked(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $orgA         = new WorkflowTenant('org-a');
        self::seedLeaveTemplate($kernel);
        $runtime = $this->runtime($kernel);
        $events  = new ArrayObject();
        self::service($kernel, EventDispatcherInterface::class, 'event_dispatcher')->addListener('workflow.leave.entered', static function () use ($events): void {
            $events->append('entered');
        });
        self::service($kernel, EventDispatcherInterface::class, 'event_dispatcher')->addListener('workflow.leave.guard', static function () use ($events): void {
            $events->append('guard');
        });

        $leave = self::persistLeave($kernel, new LeaveRequest('leave-1', 'org-a', 1));
        $first = $runtime->pin($leave, 'leave');
        self::assertTrue($first->created);
        self::assertFalse($first->replaced);
        self::assertSame(1, $first->definitionVersion);
        self::assertNull($first->definitionTenant);
        self::assertSame('leave-1', $first->subject->id);
        self::assertSame('requested', $leave->getStatus(), 'no initial transition, no automatic transition');
        self::assertCount(0, $events, 'no workflow event');
        self::assertSame([], self::audit($kernel)->events);
        self::assertSame(1, $this->pinCount($kernel));

        $again = $runtime->pin($leave, 'leave');
        self::assertFalse($again->created, 'idempotent');
        self::assertSame(1, $this->pinCount($kernel));

        // The tenant customizes the template: the pinned subject stays, unless a re-pin is requested.
        $clone = self::versioning($kernel)->cloneTemplate('leave', $orgA, publish: true);
        self::assertNull($runtime->pin($leave, 'leave')->definitionTenant);
        $same = $runtime->pin($leave, 'leave', WorkflowPinMode::KeepExisting);
        self::assertFalse($same->created || $same->replaced);
        $moved = $runtime->pin($leave, 'leave', WorkflowPinMode::Repin);
        self::assertTrue($moved->replaced);
        self::assertFalse($moved->created);
        self::assertSame('org-a', $moved->definitionTenant);
        self::assertSame(1, $moved->previousDefinitionVersion);
        self::assertSame('org-a', $runtime->marking($leave, 'leave')->definitionTenant);
        $noop = $runtime->pin($leave, 'leave', WorkflowPinMode::Repin);
        self::assertFalse($noop->replaced, 'already on that version');
        self::assertSame(1, $this->pinCount($kernel));
        self::assertCount(0, $events);

        // A re-pin never strands a subject in a place the new version does not have.
        $escalated = self::persistLeave($kernel, new LeaveRequest('leave-2', 'org-a', 1));
        $runtime->pin($escalated, 'leave');
        $escalated->setStatus('escalated');
        self::versioning($kernel)->retire($clone);
        $template = self::repository($kernel)->findCurrent('leave', null);
        self::assertInstanceOf(WorkflowDefinition::class, $template);
        $notThere = self::persistLeave($kernel, new LeaveRequest('leave-3', 'org-a', 1));
        $notThere->setStatus('escalated');
        self::assertTrue($runtime->pin($notThere, 'leave', WorkflowPinMode::Repin)->created, 'not pinned yet: plain pin on the template');
        self::versioning($kernel)->reinstate($clone);
        try {
            $runtime->pin($notThere, 'leave', WorkflowPinMode::Repin);
            self::fail('"escalated" does not exist in the clone.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"escalated"', $exception->getMessage());
        }

        // A pin that names another tenant's version is refused, never followed.
        $foreign = self::seedLeaveTemplate($kernel, 'days <= 0', new WorkflowTenant('org-c'));
        $pins    = self::repository($kernel)->bundleMetadata(WorkflowSubjectPin::class);
        self::repository($kernel)->connection()->executeStatement('UPDATE ' . $pins->getTableName() . ' SET definition_id = ? WHERE subject_ref = ?', [$foreign->getId(), 'leave-1']);
        foreach ([WorkflowPinMode::KeepExisting, WorkflowPinMode::Repin] as $mode) {
            try {
                $runtime->pin($leave, 'leave', $mode);
                self::fail($mode->value);
            } catch (WorkflowNotFoundException) {
            }
        }
    }

    #[Test]
    public function pinNeedsTenancyAndASubjectReference(): void
    {
        $this->kernel = $kernel = self::boot('plain');
        self::seedLeaveTemplate($kernel);

        try {
            $this->runtime($kernel)->pin(new LeaveRequest('leave-1', '', 1), 'leave');
            self::fail('No tenancy.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('needs tenancy', $exception->getMessage());
        }
        $kernel->shutdown();

        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it cannot be pinned');
        $this->runtime($kernel)->pin(new LeaveRequestWithoutReference(), 'leave');
    }

    private function runtime(TenancyTestKernel $kernel): WorkflowRuntimeInterface
    {
        return self::service($kernel, WorkflowRuntimeInterface::class, 'test.' . WorkflowRuntimeInterface::class);
    }

    private function pinCount(TenancyTestKernel $kernel): int
    {
        return (int) self::repository($kernel)->connection()->fetchOne('SELECT COUNT(*) FROM ' . self::repository($kernel)->bundleMetadata(WorkflowSubjectPin::class)->getTableName());
    }
}
