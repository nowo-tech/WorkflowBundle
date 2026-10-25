<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoChangeRequest;
use App\Entity\DemoDocument;
use App\Entity\DemoExpense;
use App\Entity\DemoOrder;
use App\Entity\DemoPurchaseOrder;
use App\Form\PlaygroundSubjectType;
use App\Form\ResolverFilterType;
use App\Form\TransitionType;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

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
            'tenant'        => $request->query->get('tenant'),
            'department'    => $request->query->get('department'),
            'region'        => $request->query->get('region'),
            'amount_tier'   => $request->query->get('amount_tier'),
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
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                if ($parameters !== []) {
                    $matches = $this->workflowResolver->findMatching(new WorkflowContext($subjectClass, $parameters));
                }
            }
        }

        $filterForm = $this->container->get('form.factory')->createNamed('', ResolverFilterType::class, [
            'subject_class' => $subjectClass,
        ] + $parameters);

        return $this->render('demo/resolver.html.twig', [
            'filter_form'   => $filterForm->createView(),
            'subject_class' => $subjectClass,
            'parameters'    => $parameters,
            'resolved'      => $resolved,
            'matches'       => $matches,
            'error'         => $error,
        ]);
    }

    #[Route('/playground/orders', name: 'demo_orders')]
    public function orders(): Response
    {
        return $this->renderSubjectList(
            DemoOrder::class,
            'demo/orders.html.twig',
            'orders',
            'order',
            'demo_orders_new',
            'demo_orders_transition',
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
            'change_request',
            'demo_change_requests_new',
            'demo_change_requests_transition',
            workflowSlug: 'change_request_review',
            extra: [
                'workflow_definition' => $definition,
            ],
        );
    }

    #[Route('/playground/change-requests/new', name: 'demo_change_requests_new', methods: ['POST'])]
    public function createChangeRequest(Request $request): Response
    {
        $data = $this->submittedCreateForm('change_request', 'demo_change_requests_new', $request);
        if ($data === null) {
            return $this->redirectToRoute('demo_change_requests');
        }
        $title         = trim((string) ($data['title'] ?? 'Database migration plan'));
        $changeRequest = new DemoChangeRequest($title !== '' ? $title : 'Database migration plan');
        $this->entityManager->persist($changeRequest);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_change_requests');
    }

    #[Route('/playground/change-requests/{id}/transition/{transition}', name: 'demo_change_requests_transition', methods: ['POST'])]
    public function applyChangeRequestTransition(Request $request, DemoChangeRequest $changeRequest, string $transition): Response
    {
        $form = $this->createTransitionForm('demo_change_requests_transition', (int) $changeRequest->getId(), $transition);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->workflowApplicator->applyForSubject($changeRequest, $transition);
        }

        return $this->redirectToRoute('demo_change_requests');
    }

    #[Route('/playground/orders/new', name: 'demo_orders_new', methods: ['POST'])]
    public function createOrder(Request $request): Response
    {
        $data = $this->submittedCreateForm('order', 'demo_orders_new', $request);
        if ($data === null) {
            return $this->redirectToRoute('demo_orders');
        }
        $title = trim((string) ($data['title'] ?? 'Demo order'));
        $order = new DemoOrder($title !== '' ? $title : 'Demo order');
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_orders');
    }

    #[Route('/playground/orders/{id}/transition/{transition}', name: 'demo_orders_transition', methods: ['POST'])]
    public function applyOrderTransition(Request $request, DemoOrder $order, string $transition): Response
    {
        $form = $this->createTransitionForm('demo_orders_transition', (int) $order->getId(), $transition);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->workflowApplicator->applyForSubject($order, $transition);
        }

        return $this->redirectToRoute('demo_orders');
    }

    #[Route('/playground/documents', name: 'demo_documents')]
    public function documents(): Response
    {
        return $this->renderSubjectList(
            DemoDocument::class,
            'demo/documents.html.twig',
            'documents',
            'document',
            'demo_documents_new',
            'demo_documents_transition',
            arrayMarking: true,
        );
    }

    #[Route('/playground/documents/new', name: 'demo_documents_new', methods: ['POST'])]
    public function createDocument(Request $request): Response
    {
        $data = $this->submittedCreateForm('document', 'demo_documents_new', $request);
        if ($data === null) {
            return $this->redirectToRoute('demo_documents');
        }
        $title    = trim((string) ($data['title'] ?? 'Demo document'));
        $type     = (string) ($data['document_type'] ?? 'invoice');
        $document = new DemoDocument($title !== '' ? $title : 'Demo document', $type);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_documents');
    }

    #[Route('/playground/documents/{id}/transition/{transition}', name: 'demo_documents_transition', methods: ['POST'])]
    public function applyDocumentTransition(Request $request, DemoDocument $document, string $transition): Response
    {
        $form = $this->createTransitionForm('demo_documents_transition', (int) $document->getId(), $transition);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->workflowApplicator->applyForSubject($document, $transition);
        }

        return $this->redirectToRoute('demo_documents');
    }

    #[Route('/playground/expenses', name: 'demo_expenses')]
    public function expenses(): Response
    {
        return $this->renderSubjectList(
            DemoExpense::class,
            'demo/expenses.html.twig',
            'expenses',
            'expense',
            'demo_expenses_new',
            'demo_expenses_transition',
        );
    }

    #[Route('/playground/expenses/new', name: 'demo_expenses_new', methods: ['POST'])]
    public function createExpense(Request $request): Response
    {
        $data = $this->submittedCreateForm('expense', 'demo_expenses_new', $request);
        if ($data === null) {
            return $this->redirectToRoute('demo_expenses');
        }
        $expense = new DemoExpense(
            trim((string) ($data['title'] ?? 'Team lunch')),
            (string) ($data['tenant'] ?? 'acme'),
            (string) ($data['department'] ?? 'finance'),
        );
        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_expenses');
    }

    #[Route('/playground/expenses/{id}/transition/{transition}', name: 'demo_expenses_transition', methods: ['POST'])]
    public function applyExpenseTransition(Request $request, DemoExpense $expense, string $transition): Response
    {
        $form = $this->createTransitionForm('demo_expenses_transition', (int) $expense->getId(), $transition);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->workflowApplicator->applyForSubject($expense, $transition);
        }

        return $this->redirectToRoute('demo_expenses');
    }

    #[Route('/playground/purchase-orders', name: 'demo_purchase_orders')]
    public function purchaseOrders(): Response
    {
        return $this->renderSubjectList(
            DemoPurchaseOrder::class,
            'demo/purchase_orders.html.twig',
            'purchase_orders',
            'purchase_order',
            'demo_purchase_orders_new',
            'demo_purchase_orders_transition',
        );
    }

    #[Route('/playground/purchase-orders/new', name: 'demo_purchase_orders_new', methods: ['POST'])]
    public function createPurchaseOrder(Request $request): Response
    {
        $data = $this->submittedCreateForm('purchase_order', 'demo_purchase_orders_new', $request);
        if ($data === null) {
            return $this->redirectToRoute('demo_purchase_orders');
        }
        $po = new DemoPurchaseOrder(
            trim((string) ($data['title'] ?? 'Server hardware')),
            (string) ($data['tenant'] ?? 'acme'),
            (string) ($data['region'] ?? 'eu'),
            (string) ($data['amount_tier'] ?? 'high'),
        );
        $this->entityManager->persist($po);
        $this->entityManager->flush();

        return $this->redirectToRoute('demo_purchase_orders');
    }

    #[Route('/playground/purchase-orders/{id}/transition/{transition}', name: 'demo_purchase_orders_transition', methods: ['POST'])]
    public function applyPurchaseOrderTransition(Request $request, DemoPurchaseOrder $po, string $transition): Response
    {
        $form = $this->createTransitionForm('demo_purchase_orders_transition', (int) $po->getId(), $transition);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->workflowApplicator->applyForSubject($po, $transition);
        }

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
        string $subjectType,
        string $createRoute,
        string $transitionRoute,
        bool $arrayMarking = false,
        ?string $workflowSlug = null,
        array $extra = [],
    ): Response {
        $subjects = $this->entityManager->getRepository($entityClass)->findBy([], ['id' => 'DESC']);
        $items    = [];

        foreach ($subjects as $subject) {
            $definition  = $this->workflowApplicator->resolveForSubject($subject);
            $transitions = $this->workflowApplicator->getEnabledTransitionsForSubject($subject);
            $forms       = [];
            foreach ($transitions as $transition) {
                $forms[$transition] = $this->createTransitionForm($transitionRoute, (int) $subject->getId(), $transition)->createView();
            }
            $items[] = [
                'entity'           => $subject,
                'workflow_slug'    => $definition->getSlug(),
                'workflow_name'    => $definition->getName(),
                'match_parameters' => $definition->getMatchParameters(),
                'transitions'      => $transitions,
                'transition_forms' => $forms,
                'marking'          => $arrayMarking && method_exists($subject, 'getStatus')
                    ? implode(', ', $subject->getStatus())
                    : (method_exists($subject, 'getStatus') ? $subject->getStatus() : ''),
            ];
        }

        return $this->render($template, array_merge([
            $variable       => $items,
            'workflow_slug' => $workflowSlug,
            'create_form'   => $this->createSubjectForm($subjectType, $createRoute)->createView(),
        ], $extra));
    }

    /**
     * @return FormInterface<array<string, string|null>>
     */
    private function createSubjectForm(string $subjectType, string $createRoute): FormInterface
    {
        $defaults = match ($subjectType) {
            'change_request' => ['title' => 'Upgrade payment gateway'],
            'expense'        => ['title' => 'Team lunch'],
            'purchase_order' => ['title' => 'Server hardware', 'tenant' => 'acme'],
            default          => [],
        };

        return $this->createForm(PlaygroundSubjectType::class, $defaults, [
            'subject' => $subjectType,
            'action'  => $this->generateUrl($createRoute),
        ]);
    }

    /**
     * Handles a playground "create" POST; returns the submitted data, or null when the form is not valid (CSRF).
     *
     * @return array<string, string|null>|null
     */
    private function submittedCreateForm(string $subjectType, string $createRoute, Request $request): ?array
    {
        $form = $this->createSubjectForm($subjectType, $createRoute);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return null;
        }

        /** @var array<string, string|null> $data */
        $data = $form->getData();

        return $data;
    }

    /**
     * CSRF-only form for one transition button (REQ-TWIG-005).
     *
     * @return FormInterface<null>
     */
    private function createTransitionForm(string $route, int $id, string $transition): FormInterface
    {
        return $this->container->get('form.factory')->createNamed(
            'transition_' . $id . '_' . $transition,
            TransitionType::class,
            null,
            ['action' => $this->generateUrl($route, ['id' => $id, 'transition' => $transition])],
        );
    }
}
