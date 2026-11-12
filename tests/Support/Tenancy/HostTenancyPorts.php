<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowSubjectReferenceResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectVariablesProviderInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTenantResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTransitionPermissionCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiTenantResolverInterface;
use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Model\WorkflowTransitionPermissionCheck;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Model\WorkflowUiAction;
use Nowo\WorkflowBundle\Model\WorkflowUiActionCheck;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;

use function is_string;
use function str_starts_with;

/**
 * The host side of the tenancy tests, Beacon style: one class implementing every port.
 *
 * - tenant of a leave request: its organization; subject reference: its UUID;
 * - UI scope: the `{uuid}` of the route prefix (`global` = the templates);
 * - UI actions: `root` everything everywhere; `admin-<org>` view / edit / publish in <org>; `viewer-<org>` view in
 *   <org> only; nobody else;
 * - transition permission `leave.approve`: `manager-<org>` of the subject's organization only;
 * - condition variables: `days`, `type`.
 */
final class HostTenancyPorts implements WorkflowTenantResolverInterface, WorkflowSubjectReferenceResolverInterface, WorkflowUiTenantResolverInterface, WorkflowUiActionCheckerInterface, WorkflowTransitionPermissionCheckerInterface, WorkflowSubjectVariablesProviderInterface
{
    public function tenantOf(WorkflowSubject $subject): ?WorkflowTenant
    {
        return $subject->object instanceof LeaveRequest && $subject->object->getOrganization() !== '' ? new WorkflowTenant($subject->object->getOrganization()) : null;
    }

    public function referenceOf(WorkflowSubject $subject): ?WorkflowSubjectRef
    {
        return $subject->object instanceof LeaveRequest ? new WorkflowSubjectRef($subject->object->getUuid()) : null;
    }

    public function tenantFor(WorkflowUiAccessCheck $request): ?WorkflowTenant
    {
        $uuid = $request->routeParam('uuid');

        return !is_string($uuid) || $uuid === 'global' ? null : new WorkflowTenant($uuid);
    }

    public function allows(WorkflowUiActionCheck $check): bool
    {
        $actor = $check->request->actor?->userIdentifier;
        if ($actor === 'root') {
            return true;
        }
        if ($actor === null || !$check->tenant instanceof WorkflowTenant) {
            return false;
        }
        if ($actor === 'admin-' . $check->tenant->key) {
            return true;
        }

        return $actor === 'viewer-' . $check->tenant->key && $check->action === WorkflowUiAction::View;
    }

    public function isGranted(WorkflowTransitionPermissionCheck $check): bool
    {
        return $check->permission === 'leave.approve'
            && $check->tenant instanceof WorkflowTenant
            && $check->actor !== null
            && $check->actor->id === 'manager-' . $check->tenant->key
            && $check->subject !== null
            && str_starts_with($check->subject->id, 'leave-');
    }

    public function variableNames(string $subjectClass): array
    {
        return $subjectClass === LeaveRequest::class ? ['days', 'type'] : [];
    }

    public function variablesOf(WorkflowSubject $subject): WorkflowSubjectVariables
    {
        return $subject->object instanceof LeaveRequest
            ? new WorkflowSubjectVariables(['days' => $subject->object->getDays(), 'type' => $subject->object->getType()])
            : new WorkflowSubjectVariables([]);
    }
}
