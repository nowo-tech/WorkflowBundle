<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;

/**
 * Seeds demo workflow definitions for playgrounds and integration tests.
 */
final class DemoSeedService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowDefinitionRepository $repository,
        private readonly DatabaseWorkflowRegistry $registry,
    ) {
    }

    public function seed(bool $fresh = false): void
    {
        if ($fresh) {
            foreach ($this->repository->findAll() as $existing) {
                $this->entityManager->remove($existing);
            }

            $this->entityManager->flush();
            $this->registry->invalidate();
        }

        $this->seedDefaultOrderApproval();
        $this->seedChangeRequestWorkflow();
        $this->seedDocumentTypeWorkflows();
        $this->seedExpenseWorkflows();
        $this->seedPurchaseOrderWorkflows();
        $this->entityManager->flush();
        $this->registry->invalidate();
    }

    private function seedDefaultOrderApproval(): void
    {
        if ($this->repository->findOneBySlug('order_approval_default') instanceof WorkflowDefinition) {
            return;
        }

        $definition = $this->createStateMachine(
            name: 'Order approval (default)',
            slug: 'order_approval_default',
            subjectClass: 'App\\Entity\\DemoOrder',
            initialPlace: 'draft',
            priority: 0,
            description: 'Default order workflow when no specific match rules apply.',
        );

        $this->addStandardApprovalFlow($definition);
        $this->entityManager->persist($definition);
    }

    private function seedChangeRequestWorkflow(): void
    {
        if ($this->repository->findOneBySlug('change_request_review') instanceof WorkflowDefinition) {
            return;
        }

        $definition = $this->createStateMachine(
            name: 'Change request review (branching)',
            slug: 'change_request_review',
            subjectClass: 'App\\Entity\\DemoChangeRequest',
            initialPlace: 'draft',
            priority: 0,
            description: 'Branching demo: approve, reject, request changes, resubmit and reopen loops.',
        );

        $this->addPlaces($definition, [
            'draft',
            'review',
            'changes_needed',
            'approved',
            'rejected',
            'cancelled',
        ]);

        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit for review'));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
        $definition->addTransition(new WorkflowTransition('reject', ['review'], ['rejected'], 'Reject'));
        $definition->addTransition(new WorkflowTransition('request_changes', ['review'], ['changes_needed'], 'Request changes'));
        $definition->addTransition(new WorkflowTransition('resubmit', ['changes_needed'], ['review'], 'Resubmit'));
        $definition->addTransition(new WorkflowTransition('reopen', ['rejected'], ['draft'], 'Reopen'));
        $definition->addTransition(new WorkflowTransition('cancel_from_draft', ['draft'], ['cancelled'], 'Cancel'));
        $definition->addTransition(new WorkflowTransition('cancel_from_review', ['review'], ['cancelled'], 'Cancel'));

        $this->entityManager->persist($definition);
    }

    private function seedDocumentTypeWorkflows(): void
    {
        if (!$this->repository->findOneBySlug('invoice_processing') instanceof WorkflowDefinition) {
            $invoice = $this->createStateMachine(
                name: 'Invoice processing',
                slug: 'invoice_processing',
                subjectClass: 'App\\Entity\\DemoDocument',
                initialPlace: 'draft',
                priority: 10,
                description: 'Single-parameter match: document_type=invoice',
            );
            $invoice->addMatchRule(new WorkflowMatchRule('document_type', 'invoice'));
            $this->addPlaces($invoice, ['draft', 'validated', 'paid']);
            $invoice->addTransition(new WorkflowTransition('validate', ['draft'], ['validated'], 'Validate'));
            $invoice->addTransition(new WorkflowTransition('pay', ['validated'], ['paid'], 'Mark paid'));
            $this->entityManager->persist($invoice);
        }

        if (!$this->repository->findOneBySlug('contract_review') instanceof WorkflowDefinition) {
            $contract = $this->createWorkflow(
                name: 'Contract review',
                slug: 'contract_review',
                subjectClass: 'App\\Entity\\DemoDocument',
                initialPlace: 'draft',
                priority: 10,
                description: 'Single-parameter match: document_type=contract',
            );
            $contract->addMatchRule(new WorkflowMatchRule('document_type', 'contract'));
            $this->addPlaces($contract, ['draft', 'legal_review', 'signed']);
            $contract->addTransition(new WorkflowTransition('to_legal', ['draft'], ['legal_review'], 'Legal review'));
            $contract->addTransition(new WorkflowTransition('sign', ['legal_review'], ['signed'], 'Sign'));
            $this->entityManager->persist($contract);
        }
    }

    private function seedExpenseWorkflows(): void
    {
        if (!$this->repository->findOneBySlug('acme_finance_expense') instanceof WorkflowDefinition) {
            $finance = $this->createStateMachine(
                name: 'ACME finance expense',
                slug: 'acme_finance_expense',
                subjectClass: 'App\\Entity\\DemoExpense',
                initialPlace: 'draft',
                priority: 20,
                description: 'Two-parameter match: tenant=acme + department=finance',
            );
            $finance->addMatchRule(new WorkflowMatchRule('tenant', 'acme', 0));
            $finance->addMatchRule(new WorkflowMatchRule('department', 'finance', 1));
            $this->addStandardApprovalFlow($finance);
            $this->entityManager->persist($finance);
        }

        if (!$this->repository->findOneBySlug('acme_hr_expense') instanceof WorkflowDefinition) {
            $hr = $this->createStateMachine(
                name: 'ACME HR expense',
                slug: 'acme_hr_expense',
                subjectClass: 'App\\Entity\\DemoExpense',
                initialPlace: 'draft',
                priority: 20,
                description: 'Two-parameter match: tenant=acme + department=hr',
            );
            $hr->addMatchRule(new WorkflowMatchRule('tenant', 'acme', 0));
            $hr->addMatchRule(new WorkflowMatchRule('department', 'hr', 1));
            $this->addPlaces($hr, ['draft', 'review', 'approved']);
            $hr->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit'));
            $hr->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
            $this->entityManager->persist($hr);
        }

        if (!$this->repository->findOneBySlug('globex_expense') instanceof WorkflowDefinition) {
            $globex = $this->createStateMachine(
                name: 'Globex expense (single param)',
                slug: 'globex_expense',
                subjectClass: 'App\\Entity\\DemoExpense',
                initialPlace: 'draft',
                priority: 5,
                description: 'Single-parameter match: tenant=globex (any department)',
            );
            $globex->addMatchRule(new WorkflowMatchRule('tenant', 'globex'));
            $this->addPlaces($globex, ['draft', 'approved']);
            $globex->addTransition(new WorkflowTransition('auto_approve', ['draft'], ['approved'], 'Auto approve'));
            $this->entityManager->persist($globex);
        }
    }

    private function seedPurchaseOrderWorkflows(): void
    {
        if (!$this->repository->findOneBySlug('acme_eu_high_po') instanceof WorkflowDefinition) {
            $high = $this->createStateMachine(
                name: 'ACME EU high-value PO',
                slug: 'acme_eu_high_po',
                subjectClass: 'App\\Entity\\DemoPurchaseOrder',
                initialPlace: 'draft',
                priority: 100,
                description: 'Three-parameter match: tenant + region + amount_tier',
            );
            $high->addMatchRule(new WorkflowMatchRule('tenant', 'acme', 0));
            $high->addMatchRule(new WorkflowMatchRule('region', 'eu', 1));
            $high->addMatchRule(new WorkflowMatchRule('amount_tier', 'high', 2));
            $this->addPlaces($high, ['draft', 'cfo_review', 'approved']);
            $high->addTransition(new WorkflowTransition('escalate', ['draft'], ['cfo_review'], 'Escalate to CFO'));
            $high->addTransition(new WorkflowTransition('approve', ['cfo_review'], ['approved'], 'CFO approve'));
            $this->entityManager->persist($high);
        }

        if (!$this->repository->findOneBySlug('acme_eu_po') instanceof WorkflowDefinition) {
            $eu = $this->createStateMachine(
                name: 'ACME EU standard PO',
                slug: 'acme_eu_po',
                subjectClass: 'App\\Entity\\DemoPurchaseOrder',
                initialPlace: 'draft',
                priority: 50,
                description: 'Two-parameter fallback for ACME EU non-high amounts',
            );
            $eu->addMatchRule(new WorkflowMatchRule('tenant', 'acme', 0));
            $eu->addMatchRule(new WorkflowMatchRule('region', 'eu', 1));
            $this->addStandardApprovalFlow($eu);
            $this->entityManager->persist($eu);
        }

        if (!$this->repository->findOneBySlug('default_purchase_order') instanceof WorkflowDefinition) {
            $default = $this->createStateMachine(
                name: 'Default purchase order',
                slug: 'default_purchase_order',
                subjectClass: 'App\\Entity\\DemoPurchaseOrder',
                initialPlace: 'draft',
                priority: 0,
                description: 'Default PO workflow when no match rules hit',
            );
            $this->addPlaces($default, ['draft', 'approved']);
            $default->addTransition(new WorkflowTransition('approve', ['draft'], ['approved'], 'Quick approve'));
            $this->entityManager->persist($default);
        }
    }

    private function createStateMachine(
        string $name,
        string $slug,
        string $subjectClass,
        string $initialPlace,
        int $priority,
        string $description,
    ): WorkflowDefinition {
        $definition = new WorkflowDefinition($name, $slug, $initialPlace, $subjectClass, WorkflowType::StateMachine);
        $definition->setPriority($priority);
        $definition->setDescription($description);
        $definition->setMarkingProperty('status');

        return $definition;
    }

    private function createWorkflow(
        string $name,
        string $slug,
        string $subjectClass,
        string $initialPlace,
        int $priority,
        string $description,
    ): WorkflowDefinition {
        $definition = new WorkflowDefinition($name, $slug, $initialPlace, $subjectClass, WorkflowType::Workflow);
        $definition->setPriority($priority);
        $definition->setDescription($description);
        $definition->setMarkingProperty('status');

        return $definition;
    }

    /** @param list<string> $places */
    private function addPlaces(WorkflowDefinition $definition, array $places): void
    {
        foreach ($places as $index => $place) {
            $definition->addPlace(new WorkflowPlace($place, ucfirst(str_replace('_', ' ', $place)), $index));
        }
    }

    private function addStandardApprovalFlow(WorkflowDefinition $definition): void
    {
        $this->addPlaces($definition, ['draft', 'review', 'approved', 'rejected']);
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review'], 'Submit for review'));
        $definition->addTransition(new WorkflowTransition('approve', ['review'], ['approved'], 'Approve'));
        $definition->addTransition(new WorkflowTransition('reject', ['review'], ['rejected'], 'Reject'));
        $definition->addTransition(new WorkflowTransition('reopen', ['rejected'], ['draft'], 'Reopen'));
    }
}
