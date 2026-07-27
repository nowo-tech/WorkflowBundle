<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '', name: 'nowo_workflow_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly int $listPageSize = 20,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $this->repository->paginateByName(
            $request->query->getInt('page', 1),
            $this->listPageSize,
        );

        return $this->render('@NowoWorkflowBundle/dashboard/index.html.twig', [
            'definitions'    => $page['items'],
            'list_total'     => $page['total'],
            'list_page'      => $page['page'],
            'list_pages'     => $page['pages'],
            'list_page_size' => $page['page_size'],
        ]);
    }
}
