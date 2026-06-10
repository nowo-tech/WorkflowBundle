<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoChangeRequest;
use App\Entity\DemoDocument;
use App\Entity\DemoExpense;
use App\Entity\DemoOrder;
use App\Entity\DemoPurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowApplicator $workflowApplicator,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowDefinitionRepository $workflowDefinitionRepository,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig', [
            'version_badge' => 'Symfony 8.0',
        ]);
    }

    #[Route('/playground/resolver', name: 'demo_resolver')]
    public function resolver(Request $request): Response
    {
        $subjectClass = (string) $request->query->get('subject_class', DemoPurchaseOrder::class);
        $parameters   = array_filter([
            'tenant' => $request->query->get('tenant'),
            'department' => $request->query->get('department'),
            'region' => $request->query->get('region'),
            'amount_tier' => $request->query->get('amount_tier'),
            'document_type' => $request->query->get('document_type'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $resolved = null;
        $matches  = [];
        $error    = null;

        if ($parameters !== [] || $request->query->has('subject_class')) {
            try {
                $context  = new WorkflowContext($subjectClass, $parameters);
                $resolved = $this->workflowResolver->resolve($context);
                $matches  = $this->workflowResolver->findMatching($context);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                if ($parameters !== []) {
                    $matches = $this->workflowResolver->findMatching(new WorkflowContext($subjectClass, $parameters));
                }
            }
        }

        return $this->render('demo/resolver.html.twig', [
            'subject_class' => $subjectClass,
            'parameters' => $parameters,
            'resolved' => $resolved,
            'matches' => $matches,
            'error' => $error,
        ]);
    }

    #[Route('/playground/orders', name: 'demo_orders')]
    public function orders(): Response
    {
        return $this->renderSubjectList(
            DemoOrder::class,
            'demo/orders.html.twig',
            'orders',
            workflowSlug: 'order_approval_default',
        );
    }

    #[Route('/playground/change-requests', name: 'demo_change_requests')]
    public function changeRequests(): Response
    {
        $definition = $this->workflowDefinitionRepository->findOneBySlug('change_request_review');

        return $this->renderSubjectList(
            DemoChangeRequest::class,
            'demo/change_requests.html.twig',
            'change_requests',
            workflowSlug: 'change_request_review',
            extra: [
                'workflow_definition' => $definition,
            ],
        );
    }

    #[Route('/playground/change-requests/new', name: 'demo_change_requests_new', methods: ['POST'])]
    public function createChangeRequest(Request $request): Response
    {
        $title = trim((string) $request->request->get('title', 'Database migration plan'));
        $changeRequest = new DemoChangeRequest($title !== '' ? $title : 'Database migration plan');
        $this->entityManager->persist($changeRequest);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_change_requests');
    }

    #[Route('/playground/change-requests/{id}/transition/{transition}', name: 'demo_change_requests_transition', methods: ['POST'])]
    public function applyChangeRequestTransition(DemoChangeRequest $changeRequest, string $transition): Response
    {
        $this->workflowApplicator->applyForSubject($changeRequest, $transition);

        return $this->redirectToRoute('demo_change_requests');
    }

    #[Route('/playground/orders/new', name: 'demo_orders_new', methods: ['POST'])]
    public function createOrder(Request $request): Response
    {
        $title = trim((string) $request->request->get('title', 'Demo order'));
        $order = new DemoOrder($title !== '' ? $title : 'Demo order');
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_orders');
    }

    #[Route('/playground/orders/{id}/transition/{transition}', name: 'demo_orders_transition', methods: ['POST'])]
    public function applyOrderTransition(DemoOrder $order, string $transition): Response
    {
        $this->workflowApplicator->applyForSubject($order, $transition);

        return $this->redirectToRoute('demo_orders');
    }

    #[Route('/playground/documents', name: 'demo_documents')]
    public function documents(): Response
    {
        return $this->renderSubjectList(
            DemoDocument::class,
            'demo/documents.html.twig',
            'documents',
            true,
        );
    }

    #[Route('/playground/documents/new', name: 'demo_documents_new', methods: ['POST'])]
    public function createDocument(Request $request): Response
    {
        $title = trim((string) $request->request->get('title', 'Demo document'));
        $type  = (string) $request->request->get('document_type', 'invoice');
        $document = new DemoDocument($title !== '' ? $title : 'Demo document', $type);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_documents');
    }

    #[Route('/playground/documents/{id}/transition/{transition}', name: 'demo_documents_transition', methods: ['POST'])]
    public function applyDocumentTransition(DemoDocument $document, string $transition): Response
    {
        $this->workflowApplicator->applyForSubject($document, $transition);

        return $this->redirectToRoute('demo_documents');
    }

    #[Route('/playground/expenses', name: 'demo_expenses')]
    public function expenses(): Response
    {
        return $this->renderSubjectList(
            DemoExpense::class,
            'demo/expenses.html.twig',
            'expenses',
        );
    }

    #[Route('/playground/expenses/new', name: 'demo_expenses_new', methods: ['POST'])]
    public function createExpense(Request $request): Response
    {
        $expense = new DemoExpense(
            trim((string) $request->request->get('title', 'Team lunch')),
            (string) $request->request->get('tenant', 'acme'),
            (string) $request->request->get('department', 'finance'),
        );
        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_expenses');
    }

    #[Route('/playground/expenses/{id}/transition/{transition}', name: 'demo_expenses_transition', methods: ['POST'])]
    public function applyExpenseTransition(DemoExpense $expense, string $transition): Response
    {
        $this->workflowApplicator->applyForSubject($expense, $transition);

        return $this->redirectToRoute('demo_expenses');
    }

    #[Route('/playground/purchase-orders', name: 'demo_purchase_orders')]
    public function purchaseOrders(): Response
    {
        return $this->renderSubjectList(
            DemoPurchaseOrder::class,
            'demo/purchase_orders.html.twig',
            'purchase_orders',
        );
    }

    #[Route('/playground/purchase-orders/new', name: 'demo_purchase_orders_new', methods: ['POST'])]
    public function createPurchaseOrder(Request $request): Response
    {
        $po = new DemoPurchaseOrder(
            trim((string) $request->request->get('title', 'Server hardware')),
            (string) $request->request->get('tenant', 'acme'),
            (string) $request->request->get('region', 'eu'),
            (string) $request->request->get('amount_tier', 'high'),
        );
        $this->entityManager->persist($po);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_purchase_orders');
    }

    #[Route('/playground/purchase-orders/{id}/transition/{transition}', name: 'demo_purchase_orders_transition', methods: ['POST'])]
    public function applyPurchaseOrderTransition(DemoPurchaseOrder $po, string $transition): Response
    {
        $this->workflowApplicator->applyForSubject($po, $transition);

        return $this->redirectToRoute('demo_purchase_orders');
    }

    /**
     * @param class-string $entityClass
     * @param array<string, mixed> $extra
     */
    private function renderSubjectList(
        string $entityClass,
        string $template,
        string $variable,
        bool $arrayMarking = false,
        ?string $workflowSlug = null,
        array $extra = [],
    ): Response {
        $subjects = $this->entityManager->getRepository($entityClass)->findBy([], ['id' => 'DESC']);
        $items    = [];

        foreach ($subjects as $subject) {
            $definition = $this->workflowApplicator->resolveForSubject($subject);
            $items[]    = [
                'entity' => $subject,
                'workflow_slug' => $definition->getSlug(),
                'workflow_name' => $definition->getName(),
                'match_parameters' => $definition->getMatchParameters(),
                'transitions' => $this->workflowApplicator->getEnabledTransitionsForSubject($subject),
                'marking' => $arrayMarking && method_exists($subject, 'getStatus')
                    ? implode(', ', $subject->getStatus())
                    : (method_exists($subject, 'getStatus') ? $subject->getStatus() : ''),
            ];
        }

        return $this->render($template, array_merge([
            $variable => $items,
            'workflow_slug' => $workflowSlug,
        ], $extra));
    }
}
