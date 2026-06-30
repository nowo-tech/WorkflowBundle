<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/definitions', name: 'nowo_workflow_definition_')]
final class WorkflowDefinitionController extends AbstractController
{
    public function __construct(
        private readonly WorkflowDefinitionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DatabaseWorkflowRegistry $registry,
        private readonly WorkflowGraphPresenter $graphPresenter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@NowoWorkflowBundle/workflow_definition/index.html.twig', [
            'definitions' => $this->repository->findBy([], ['name' => 'ASC']),
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
            $this->entityManager->flush();
            $this->registry->invalidate($definition->getSlug());

            $this->addFlash('success', 'flash.created');

            return $this->redirectToRoute('nowo_workflow_definition_edit_match_rules', ['id' => $definition->getId()]);
        }

        return $this->render('@NowoWorkflowBundle/workflow_definition/form.html.twig', [
            'form'    => $form,
            'title'   => 'page.new_definition',
            'section' => WorkflowDefinitionFormSection::General,
        ]);
    }

    #[Route('/by-slug/{slug}', name: 'show_by_slug', requirements: ['slug' => '[a-z0-9_]+'], methods: ['GET'])]
    public function showBySlug(string $slug): Response
    {
        return $this->renderShow($this->requireDefinitionBySlug($slug));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): \Symfony\Component\HttpFoundation\RedirectResponse
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
    public function delete(Request $request, int $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $definition = $this->requireDefinitionById($id);

        if (!$this->isCsrfTokenValid('delete' . $definition->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $slug = $definition->getSlug();
        $this->entityManager->remove($definition);
        $this->entityManager->flush();
        $this->registry->invalidate($slug);

        $this->addFlash('success', 'flash.deleted');

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
            $this->entityManager->flush();
            $this->registry->invalidate($previousSlug);
            $this->registry->invalidate($definition->getSlug());

            $this->addFlash('success', 'flash.updated');

            return $this->redirectToRoute($section->routeName(), ['id' => $definition->getId()]);
        }

        return $this->render('@NowoWorkflowBundle/workflow_definition/form.html.twig', [
            'form'       => $form,
            'title'      => $section->titleKey(),
            'definition' => $definition,
            'section'    => $section,
        ]);
    }

    private function renderShow(WorkflowDefinition $definition): Response
    {
        return $this->render('@NowoWorkflowBundle/workflow_definition/show.html.twig', [
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
