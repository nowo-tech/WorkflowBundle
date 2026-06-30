<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '', name: 'nowo_workflow_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@NowoWorkflowBundle/dashboard/index.html.twig', [
            'definitions' => $this->repository->findBy([], ['name' => 'ASC']),
        ]);
    }
}
