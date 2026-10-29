<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\View\WorkflowPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Workflow UI dashboard (UiKit page/dashboard scaffold, REQ-UI-003).
 */
#[Route(path: '', name: 'nowo_workflow_')]
final class DashboardController extends AbstractController
{
    /**
     * Creates the controller with the definition repository, the page factory, the list page size and the page templates.
     *
     * @param array<string, string> $templates web_ui.templates
     */
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly WorkflowPageFactory $pages,
        private readonly int $listPageSize = 20,
        private readonly array $templates = Configuration::DEFAULT_TEMPLATES,
    ) {
    }

    /**
     * Renders the Workflow UI dashboard (definition counters and the first page of definitions).
     */
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $result = $this->repository->paginateByName(1, $this->listPageSize);

        return $this->render($this->templates['dashboard'] ?? Configuration::DEFAULT_TEMPLATES['dashboard'], [
            'page'        => $this->pages->dashboard($result['total'], $this->repository->countEnabled()),
            'definitions' => $result['items'],
            'total'       => $result['total'],
        ]);
    }
}
