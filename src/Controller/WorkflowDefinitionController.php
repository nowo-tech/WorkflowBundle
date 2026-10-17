<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Doctrine\ClosedEntityManagerResetter;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\View\WorkflowPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Workflow definitions CRUD (UiKit page scaffolds, REQ-UI-003): index → page/list, show → page/show,
 * new → page/form, edit sections → page/settings.
 */
#[Route(path: '/definitions', name: 'nowo_workflow_definition_')]
final class WorkflowDefinitionController extends AbstractController
{
    /**
     * @param array<string, string> $templates web_ui.templates
     */
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DatabaseWorkflowRegistry $registry,
        private readonly WorkflowGraphPresenter $graphPresenter,
        private readonly TranslatorInterface $translator,
        private readonly WorkflowPageFactory $pages,
        private readonly int $listPageSize = 20,
        private readonly ?ManagerRegistry $managerRegistry = null,
        private readonly array $templates = Configuration::DEFAULT_TEMPLATES,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $this->repository->paginateByName(
            $request->query->getInt('page', 1),
            $this->listPageSize,
        );

        return $this->render($this->template('index'), [
            'page' => $this->pages->index($page),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $definition = new WorkflowDefinition('New workflow', 'new_workflow', 'draft', 'App\\Entity\\Subject');
        $form       = $this->createForm(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::General,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($definition);
            $this->flush();
            $this->registry->invalidate($definition->getSlug());

            $this->flash('flash.created');

            return $this->redirectToRoute('nowo_workflow_definition_edit_match_rules', ['id' => $definition->getId()]);
        }

        return $this->render($this->template('new'), [
            'page'    => $this->pages->newForm($form->createView()),
            'section' => WorkflowDefinitionFormSection::General,
        ], $this->formResponse($form));
    }

    #[Route('/by-slug/{slug}', name: 'show_by_slug', requirements: ['slug' => '[a-z0-9_]+'], methods: ['GET'])]
    public function showBySlug(string $slug): Response
    {
        return $this->renderShow($this->requireDefinitionBySlug($slug));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): RedirectResponse
    {
        return $this->redirectToRoute('nowo_workflow_definition_edit_general', ['id' => $id]);
    }

    #[Route('/{id}/edit/general', name: 'edit_general', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editGeneral(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::General);
    }

    #[Route('/{id}/edit/match-rules', name: 'edit_match_rules', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editMatchRules(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::MatchRules);
    }

    #[Route('/{id}/edit/places', name: 'edit_places', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editPlaces(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::Places);
    }

    #[Route('/{id}/edit/transitions', name: 'edit_transitions', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editTransitions(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::Transitions);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        return $this->renderShow($this->requireDefinitionById($id));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        $definition = $this->requireDefinitionById($id);

        if (!$this->isCsrfTokenValid('delete' . $definition->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $slug = $definition->getSlug();
        $this->entityManager->remove($definition);
        $this->flush();
        $this->registry->invalidate($slug);

        $this->flash('flash.deleted');

        return $this->redirectToRoute('nowo_workflow_definition_index');
    }

    private function editSection(Request $request, int $id, WorkflowDefinitionFormSection $section): Response
    {
        $definition   = $this->requireDefinitionById($id);
        $previousSlug = $definition->getSlug();
        $form         = $this->createForm(WorkflowDefinitionFormType::class, $definition, [
            'section' => $section,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->flush();
            $this->registry->invalidate($previousSlug);
            $this->registry->invalidate($definition->getSlug());

            $this->flash('flash.updated');

            return $this->redirectToRoute($section->routeName(), ['id' => $definition->getId()]);
        }

        return $this->render($this->template('edit'), [
            'page'       => $this->pages->edit($definition, $section, $form->createView()),
            'definition' => $definition,
            'section'    => $section,
        ], $this->formResponse($form));
    }

    private function template(string $page): string
    {
        return $this->templates[$page] ?? Configuration::DEFAULT_TEMPLATES[$page];
    }

    /**
     * Flash messages are translated here: the UiKit flashes partial prints them as given.
     */
    private function flash(string $key): void
    {
        $this->addFlash('success', $this->translator->trans($key, [], WorkflowPageFactory::DOMAIN));
    }

    /**
     * 422 for a submitted invalid form (what render() does for a FormInterface parameter; the view model carries a
     * FormView).
     *
     * @param FormInterface<WorkflowDefinition> $form
     */
    private function formResponse(FormInterface $form): Response
    {
        return new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function flush(): void
    {
        try {
            $this->entityManager->flush();
        } catch (Throwable $exception) {
            ClosedEntityManagerResetter::resetIfClosed($this->managerRegistry, $this->entityManager);

            throw $exception;
        }
    }

    private function renderShow(WorkflowDefinition $definition): Response
    {
        return $this->render($this->template('show'), [
            'page'       => $this->pages->show($definition),
            'definition' => $definition,
            'graph'      => $this->graphPresenter->present($definition),
        ]);
    }

    private function requireDefinitionById(int $id): WorkflowDefinition
    {
        $definition = $this->repository->find($id);
        if (!$definition instanceof WorkflowDefinition) {
            throw $this->createNotFoundException($this->translator->trans('error.not_found_id', ['%id%' => $id], 'NowoWorkflowBundle'));
        }

        return $definition;
    }

    private function requireDefinitionBySlug(string $slug): WorkflowDefinition
    {
        $definition = $this->repository->findOneBySlug($slug);
        if (!$definition instanceof WorkflowDefinition) {
            throw $this->createNotFoundException($this->translator->trans('error.not_found_slug', ['%slug%' => $slug], 'NowoWorkflowBundle'));
        }

        return $definition;
    }
}
