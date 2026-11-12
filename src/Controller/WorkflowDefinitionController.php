<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Doctrine\ClosedEntityManagerResetter;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowDefinitionInUseException;
use Nowo\WorkflowBundle\Exception\WorkflowInvalidShapeException;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Http\WorkflowUiTenancy;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Model\WorkflowUiAction;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\View\WorkflowPageFactory;
use Nowo\WorkflowBundle\View\WorkflowUiScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function implode;

/**
 * Workflow definitions CRUD (UiKit page scaffolds, REQ-UI-003): index → page/list, show → page/show,
 * new → page/form, edit sections → page/settings.
 */
#[Route(path: '/definitions', name: 'nowo_workflow_definition_')]
final class WorkflowDefinitionController extends AbstractController
{
    /**
     * Creates the controller with its persistence, registry, presentation and configuration dependencies and a PSR-3 logger.
     *
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
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly WorkflowUiTenancy $tenancy = new WorkflowUiTenancy(),
        private readonly ?WorkflowVersioning $versioning = null,
    ) {
    }

    /**
     * Lists the workflow definitions, paginated by name.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenancy->tenant($request);
        $this->tenancy->denyUnlessAllowed($request, WorkflowUiAction::View, $tenant);
        $page = $this->repository->paginateByName(
            $request->query->getInt('page', 1),
            $this->listPageSize,
            $tenant,
            $this->tenancy->enabled(),
        );

        return $this->render($this->template('index'), [
            'page'  => $this->pages->index($page, $this->scope($tenant)),
            'scope' => $this->scope($tenant),
        ]);
    }

    /**
     * Shows and handles the form that creates a workflow definition (general section).
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->tenancy->tenant($request);
        $this->tenancy->denyUnlessAllowed($request, WorkflowUiAction::Edit, $tenant);
        $definition = new WorkflowDefinition('New workflow', 'new_workflow', 'draft', 'App\\Entity\\Subject');
        if ($this->tenancy->enabled()) {
            $definition->startAsDraft($tenant);
        }
        $form = $this->createForm(WorkflowDefinitionFormType::class, $definition, [
            'section' => WorkflowDefinitionFormSection::General,
            // Audit NO-v N7: with tenancy the slug is an identifier too.
            'strict_names' => $this->tenancy->enabled(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $this->tenancy->enabled() && $this->repository->existsInScope($definition->getSlug(), $tenant)) {
            $form->get('slug')->addError(new FormError($this->translator->trans('error.slug_taken', [], WorkflowPageFactory::DOMAIN)));
        }
        if ($form->isSubmitted() && !$this->tenancy->bindTenantSubject($definition, $tenant)) {
            // Audit NO M2: a tenant only creates workflows for the subject classes the host allows (tenancy.subject_classes).
            $form->get('subjectClass')->addError(new FormError($this->translator->trans('error.subject_class_not_allowed', [], WorkflowPageFactory::DOMAIN)));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($definition);
            try {
                $this->flush();
            } catch (UniqueConstraintViolationException $exception) {
                if (!$this->tenancy->enabled()) {
                    throw $exception;
                }
                // Audit NO L3: a concurrent request created the same slug in this scope first.
                $form->get('slug')->addError(new FormError($this->translator->trans('error.slug_taken', [], WorkflowPageFactory::DOMAIN)));

                return $this->render($this->template('new'), [
                    'page'    => $this->pages->newForm($form->createView()),
                    'section' => WorkflowDefinitionFormSection::General,
                ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
            }
            $this->registry->invalidate($definition->getSlug());

            $this->flash('flash.created');

            return $this->redirectToRoute('nowo_workflow_definition_edit_match_rules', ['id' => $definition->getId()]);
        }

        return $this->render($this->template('new'), [
            'page'    => $this->pages->newForm($form->createView()),
            'section' => WorkflowDefinitionFormSection::General,
        ], $this->formResponse($form));
    }

    /**
     * Shows a workflow definition looked up by its slug.
     */
    #[Route('/by-slug/{slug}', name: 'show_by_slug', requirements: ['slug' => '[a-z0-9_]+'], methods: ['GET'])]
    public function showBySlug(Request $request, string $slug): Response
    {
        $tenant = $this->tenancy->tenant($request);

        return $this->renderShow($request, $this->requireDefinitionBySlug($slug, $tenant), $tenant);
    }

    /**
     * Redirects the legacy edit URL to the general section editor.
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): RedirectResponse
    {
        return $this->redirectToRoute('nowo_workflow_definition_edit_general', ['id' => $id]);
    }

    /**
     * Shows and handles the general section editor of a definition.
     */
    #[Route('/{id}/edit/general', name: 'edit_general', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editGeneral(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::General);
    }

    /**
     * Shows and handles the match rules editor of a definition.
     */
    #[Route('/{id}/edit/match-rules', name: 'edit_match_rules', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editMatchRules(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::MatchRules);
    }

    /**
     * Shows and handles the places editor of a definition.
     */
    #[Route('/{id}/edit/places', name: 'edit_places', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editPlaces(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::Places);
    }

    /**
     * Shows and handles the transitions editor of a definition.
     */
    #[Route('/{id}/edit/transitions', name: 'edit_transitions', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editTransitions(Request $request, int $id): Response
    {
        return $this->editSection($request, $id, WorkflowDefinitionFormSection::Transitions);
    }

    /**
     * Shows a workflow definition (details and flow diagram).
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $tenant = $this->tenancy->tenant($request);

        return $this->renderShow($request, $this->requireDefinitionById($id), $tenant);
    }

    /**
     * Creates (or reopens) the draft of a published definition of the scope (tenancy only).
     */
    #[Route('/{id}/draft', name: 'draft', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function draft(Request $request, int $id): RedirectResponse
    {
        [$definition] = $this->tenancyAction($request, $id, WorkflowUiAction::Edit, 'draft');
        $draft        = $this->versioning()->createDraftOf($definition);

        return $this->redirectToRoute('nowo_workflow_definition_edit_general', ['id' => $draft->getId()]);
    }

    /**
     * Publishes a draft of the scope: new subjects start with it (tenancy only).
     */
    #[Route('/{id}/publish', name: 'publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, int $id): RedirectResponse
    {
        [$definition] = $this->tenancyAction($request, $id, WorkflowUiAction::Publish, 'publish');
        if (!$definition->isDraft()) {
            throw $this->createNotFoundException('Only a draft can be published.');
        }
        try {
            $published = $this->versioning()->publish($definition);
        } catch (WorkflowInvalidShapeException $exception) {
            // Since 1.15.2: transitions FrameworkBundle would refuse (no source / target, several targets on a state machine).
            $this->addFlash('error', $this->translator->trans('error.invalid_shape', ['%violations%' => implode(' ', $exception->violations)], WorkflowPageFactory::DOMAIN));

            return $this->redirectToRoute('nowo_workflow_definition_show', ['id' => $id]);
        }
        $this->logger->info('Workflow definition published.', ['bundle' => 'NowoWorkflowBundle', 'action' => 'publish_definition', 'id' => $published->getId(), 'slug' => $published->getSlug(), 'version' => $published->getVersion()]);
        $this->flash('flash.published');

        return $this->redirectToRoute('nowo_workflow_definition_show', ['id' => $published->getId()]);
    }

    /**
     * Copies a global template into the current tenant as a draft (tenancy, tenant scope only).
     */
    #[Route('/{id}/clone', name: 'clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cloneTemplate(Request $request, int $id): RedirectResponse
    {
        [$template, $tenant] = $this->tenancyAction($request, $id, WorkflowUiAction::Edit, 'clone', false);
        if (!$tenant instanceof WorkflowTenant || $template->getTenantKey() !== '') {
            throw $this->createNotFoundException('Only a global template can be cloned into a tenant.');
        }

        try {
            $copy = $this->versioning()->cloneTemplate($template->getSlug(), $tenant);
        } catch (LogicException) {
            $this->addFlash('error', $this->translator->trans('error.already_customized', [], WorkflowPageFactory::DOMAIN));

            return $this->redirectToRoute('nowo_workflow_definition_index');
        }
        $this->flash('flash.cloned');

        return $this->redirectToRoute('nowo_workflow_definition_edit_general', ['id' => $copy->getId()]);
    }

    /**
     * Deletes a workflow definition after a valid CSRF token check and redirects to the list.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        $tenant     = $this->tenancy->tenant($request);
        $definition = $this->requireDefinitionById($id);
        $this->tenancy->assertInScope($definition, $tenant, true);
        $this->tenancy->denyUnlessAllowed($request, WorkflowUiAction::Publish, $tenant, $definition);

        if (!$this->isCsrfTokenValid('delete' . $definition->getId(), (string) $request->request->get('_token'))) {
            $this->logger->warning('Workflow definition delete rejected: invalid CSRF token.', [
                'bundle' => 'NowoWorkflowBundle',
                'action' => 'delete_definition',
                'id'     => $id,
            ]);

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $slug = $definition->getSlug();
        if ($this->tenancy->enabled()) {
            try {
                $this->versioning()->delete($definition);
            } catch (WorkflowDefinitionInUseException $exception) {
                // Owner decision 2026-11-12: never delete versions running subjects are pinned to.
                $this->addFlash('error', $this->translator->trans('error.definition_in_use', ['%count%' => $exception->pinnedSubjects], WorkflowPageFactory::DOMAIN));

                return $this->redirectToRoute('nowo_workflow_definition_show', ['id' => $id]);
            }
        } else {
            $this->entityManager->remove($definition);
            $this->flush();
            $this->registry->invalidate($slug);
        }
        $this->logger->info('Workflow definition deleted.', [
            'bundle' => 'NowoWorkflowBundle',
            'action' => 'delete_definition',
            'id'     => $id,
            'slug'   => $slug,
        ]);

        $this->flash('flash.deleted');

        return $this->redirectToRoute('nowo_workflow_definition_index');
    }

    private function editSection(Request $request, int $id, WorkflowDefinitionFormSection $section): Response
    {
        $tenant     = $this->tenancy->tenant($request);
        $definition = $this->requireDefinitionById($id);
        $this->tenancy->assertInScope($definition, $tenant, true);
        $this->tenancy->denyUnlessAllowed($request, WorkflowUiAction::Edit, $tenant, $definition);
        if ($this->tenancy->enabled() && !$definition->isDraft()) {
            // A published version is never edited: the editor works on a draft (show page: "New draft").
            $this->addFlash('warning', $this->translator->trans('flash.published_read_only', [], WorkflowPageFactory::DOMAIN));

            return $this->redirectToRoute('nowo_workflow_definition_show', ['id' => $id]);
        }
        $previousSlug = $definition->getSlug();
        $form         = $this->createForm(WorkflowDefinitionFormType::class, $definition, [
            'section'     => $section,
            'guards'      => $this->tenancy->guardFields($definition),
            'slug_locked' => $this->tenancy->enabled(),
            // Audit NO M2: in a tenant scope the runtime binding (type, subject class, marking property) is fixed and
            // place / transition names are identifiers.
            'binding_locked' => $tenant instanceof WorkflowTenant,
            'strict_names'   => $this->tenancy->enabled(),
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
            'page'       => $this->pages->edit($definition, $section, $form->createView(), $this->scope($tenant)),
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

    private function renderShow(Request $request, WorkflowDefinition $definition, ?WorkflowTenant $tenant): Response
    {
        $this->tenancy->assertInScope($definition, $tenant, false);
        $this->tenancy->denyUnlessAllowed($request, WorkflowUiAction::View, $tenant, $definition);

        return $this->render($this->template('show'), [
            'page'       => $this->pages->show($definition, $this->scope($tenant)),
            'definition' => $definition,
            'graph'      => $this->graphPresenter->present($definition),
            'scope'      => $this->scope($tenant),
        ]);
    }

    private function scope(?WorkflowTenant $tenant): WorkflowUiScope
    {
        return new WorkflowUiScope($this->tenancy->enabled(), $tenant);
    }

    private function versioning(): WorkflowVersioning
    {
        // Wired by the extension; only a hand-built controller lacks it (tenancyAction() answered 404 already).
        return $this->versioning ?? throw new LogicException('WorkflowVersioning is not wired.');
    }

    /**
     * Shared checks of the tenancy-only POST actions: tenancy on (404 otherwise), the definition in scope, the
     * host's action checker and the CSRF token "<intention><id>".
     *
     * @return array{0: WorkflowDefinition, 1: WorkflowTenant|null}
     */
    private function tenancyAction(Request $request, int $id, WorkflowUiAction $action, string $intention, bool $write = true): array
    {
        if (!$this->tenancy->enabled() || !$this->versioning instanceof WorkflowVersioning) {
            throw $this->createNotFoundException('Tenancy is not enabled.');
        }
        $tenant     = $this->tenancy->tenant($request);
        $definition = $this->requireDefinitionById($id);
        $this->tenancy->assertInScope($definition, $tenant, $write);
        $this->tenancy->denyUnlessAllowed($request, $action, $tenant, $definition);

        if (!$this->isCsrfTokenValid($intention . $id, (string) $request->request->get('_token'))) {
            $this->logger->warning('Workflow definition action rejected: invalid CSRF token.', ['bundle' => 'NowoWorkflowBundle', 'action' => $intention, 'id' => $id]);

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return [$definition, $tenant];
    }

    private function requireDefinitionById(int $id): WorkflowDefinition
    {
        $definition = $this->repository->find($id);
        if (!$definition instanceof WorkflowDefinition) {
            throw $this->createNotFoundException($this->translator->trans('error.not_found_id', ['%id%' => $id], 'NowoWorkflowBundle'));
        }

        return $definition;
    }

    private function requireDefinitionBySlug(string $slug, ?WorkflowTenant $tenant): WorkflowDefinition
    {
        $definition = !$this->tenancy->enabled()
            ? $this->repository->findOneBySlug($slug)
            : ($this->repository->findDraft($slug, $tenant) ?? $this->repository->findCurrent($slug, $tenant) ?? ($tenant instanceof WorkflowTenant ? $this->repository->findCurrent($slug, null) : null));
        if (!$definition instanceof WorkflowDefinition) {
            throw $this->createNotFoundException($this->translator->trans('error.not_found_slug', ['%slug%' => $slug], 'NowoWorkflowBundle'));
        }

        return $definition;
    }
}
