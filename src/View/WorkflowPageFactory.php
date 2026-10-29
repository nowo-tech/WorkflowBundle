<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\View;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\KpiTile;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Twig\WorkflowExtension;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Builds the UiKit view models of the Workflow admin pages (REQ-UI-003).
 *
 * Stateless (worker safe): every method returns a new immutable view model built from its arguments; labels are
 * {@see Label}s of the NowoWorkflowBundle domain, translated by the UiKit scaffolds.
 */
final class WorkflowPageFactory
{
    public const BUNDLE = 'NowoWorkflowBundle';

    public const DOMAIN = 'NowoWorkflowBundle';

    /**
     * Creates the factory with the URL generator, the CSRF token manager, the layout and the 1.x form theme stack.
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ?string $layout = null,
        private readonly ?string $formThemeFramework = null,
    ) {
    }

    /**
     * Returns a translatable label of the NowoWorkflowBundle domain.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Builds the dashboard page view model.
     *
     * @param int $total all definitions
     * @param int $enabled enabled definitions
     */
    public function dashboard(int $total, int $enabled): DashboardPage
    {
        return new DashboardPage(
            new PageHeader(
                self::label('dashboard.title'),
                self::label('dashboard.intro', ['%class%' => 'DatabaseWorkflowRegistry']),
                [$this->createAction()],
            ),
            [
                new KpiTile(self::label('kpi.definitions'), $total, self::label('kpi.definitions_hint'), $this->url('nowo_workflow_definition_index'), linkTile: true),
                new KpiTile(self::label('kpi.enabled'), $enabled, variant: 'success'),
                new KpiTile(self::label('kpi.disabled'), max(0, $total - $enabled), variant: $total > $enabled ? 'warning' : 'neutral'),
            ],
            self::BUNDLE,
            $this->layout,
            ['data-testid' => 'workflow-dashboard'],
        );
    }

    /**
     * @param array{items: list<WorkflowDefinition>, total: int, page: int, pages: int, page_size: int} $result
     *                                                                                                          {@see WorkflowDefinitionRepository::paginateByName()}
     */
    public function index(array $result): ListPage
    {
        return new ListPage(
            new PageHeader(
                self::label('definition.index_title'),
                null,
                [$this->createAction()],
                [new Crumb(self::label('nav.dashboard'), $this->url('nowo_workflow_dashboard')), new Crumb(self::label('nav.definitions'))],
            ),
            [
                new Column('name', self::label('table.name'), required: true),
                new Column('slug', self::label('table.slug')),
                new Column('matchRules', self::label('table.match_rules'), align: 'end', priority: 2),
                new Column('priority', self::label('table.priority'), align: 'end', priority: 3),
                new Column('places', self::label('table.places'), align: 'end', priority: 2),
                new Column('transitions', self::label('table.transitions'), align: 'end', priority: 2),
            ],
            $result['items'],
            rowActions: fn (WorkflowDefinition $definition): array => $this->rowActions($definition),
            pagination: $result['page_size'] > 0
                ? new Pagination($result['page'], $result['page_size'], $result['total'], $result['pages'], 'nowo_workflow_definition_index')
                : null,
            empty: new EmptyState(self::label('definition.empty_title'), self::label('definition.empty_message'), $this->createAction()),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'workflow-definitions'],
        );
    }

    /**
     * View, edit and delete actions of one definition row.
     *
     * @return list<Action>
     */
    public function rowActions(WorkflowDefinition $definition): array
    {
        $id = (int) $definition->getId();

        return [
            Action::view($this->url('nowo_workflow_definition_show', ['id' => $id]), self::label('action.view')),
            Action::edit($this->url('nowo_workflow_definition_edit_general', ['id' => $id]), self::label('action.edit')),
            $this->deleteAction($definition),
        ];
    }

    /**
     * Builds the show page view model of a definition.
     */
    public function show(WorkflowDefinition $definition): ShowPage
    {
        $id = (int) $definition->getId();

        return new ShowPage(
            new PageHeader(
                $definition->getName(),
                $definition->getDescription(),
                [
                    new Action('edit', self::label('action.edit'), $this->url('nowo_workflow_definition_edit_general', ['id' => $id]), variant: 'primary'),
                    $this->deleteAction($definition),
                ],
                $this->definitionCrumbs($definition, false),
            ),
            [
                new DetailSection(self::label('definition.section.general'), [
                    new DetailItem(self::label('table.slug'), $definition->getSlug()),
                    new DetailItem(self::label('table.type'), self::label('workflow_type.' . $definition->getType()->value)),
                    new DetailItem(self::label('table.status'), self::label($definition->isEnabled() ? 'status.enabled' : 'status.disabled')),
                    new DetailItem(self::label('table.priority'), $definition->getPriority()),
                ], 'workflow-definition-general'),
                new DetailSection(self::label('definition.runtime_binding'), [
                    new DetailItem(self::label('definition.subject_class'), $definition->getSubjectClass()),
                    new DetailItem(self::label('definition.marking_property'), $definition->getMarkingProperty()),
                    new DetailItem(self::label('definition.initial_place'), $definition->getInitialPlace()),
                    new DetailItem(self::label('definition.places_transitions'), $definition->getPlaces()->count() . ' / ' . $definition->getTransitions()->count()),
                ], 'workflow-definition-binding'),
                new DetailSection(self::label('definition.match_rules'), $this->matchRuleItems($definition), 'workflow-definition-match-rules'),
            ],
            [
                new DetailItem(self::label('definition.created_at'), $definition->getCreatedAt()->format('Y-m-d H:i')),
                new DetailItem(self::label('definition.updated_at'), $definition->getUpdatedAt()->format('Y-m-d H:i')),
            ],
            self::BUNDLE,
            $this->layout,
            ['data-testid' => 'workflow-definition-show'],
        );
    }

    /**
     * Builds the form page view model of the new-definition form.
     */
    public function newForm(FormView $form): FormPage
    {
        return new FormPage(
            new PageHeader(
                self::label('page.new_definition'),
                self::label('form.help.new_definition'),
                [],
                [new Crumb(self::label('nav.dashboard'), $this->url('nowo_workflow_dashboard')), new Crumb(self::label('nav.definitions'), $this->url('nowo_workflow_definition_index')), new Crumb(self::label('nav.new_definition'))],
            ),
            $form,
            self::label('action.create_continue'),
            $this->url('nowo_workflow_definition_index'),
            self::label('action.cancel'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'workflow-definition-new'],
            formThemes: $this->formThemes(),
        );
    }

    /**
     * Edit page: one settings section (tab) per {@see WorkflowDefinitionFormSection}; only the current one carries
     * the form.
     */
    public function edit(WorkflowDefinition $definition, WorkflowDefinitionFormSection $current, FormView $form): SettingsPage
    {
        $id       = (int) $definition->getId();
        $sections = [];
        foreach (WorkflowDefinitionFormSection::cases() as $section) {
            $isCurrent  = $section === $current;
            $sections[] = new SettingsSection(
                $section->value,
                self::label('form.section.' . $section->value),
                $this->url($section->routeName(), ['id' => $id]),
                $isCurrent ? $form : null,
                $isCurrent ? self::label($section->helpKey()) : null,
                self::label('action.save'),
                $isCurrent ? $this->formThemes() : [],
            );
        }

        return new SettingsPage(
            new PageHeader(
                self::label($current->titleKey()),
                $definition->getName() . ' · ' . $definition->getSlug(),
                [
                    Action::view($this->url('nowo_workflow_definition_show', ['id' => $id]), self::label('action.view')),
                    $this->deleteAction($definition),
                ],
                $this->definitionCrumbs($definition, true),
            ),
            $sections,
            $current->value,
            self::BUNDLE,
            $this->layout,
            ['data-testid' => 'workflow-definition-edit'],
        );
    }

    /**
     * Symfony form themes of the Workflow forms (UiKit 2.1 FormPage / SettingsSection formThemes): only while the
     * deprecated ui.css_framework is set (1.x behaviour); otherwise FormKit styles the forms.
     *
     * @return list<string>
     */
    private function formThemes(): array
    {
        return $this->formThemeFramework === null ? [] : WorkflowExtension::formThemesFor($this->formThemeFramework);
    }

    /**
     * POST delete (CSRF token "delete<id>", field _token) behind the UiKit confirm dialog.
     */
    public function deleteAction(WorkflowDefinition $definition): Action
    {
        $id = (int) $definition->getId();

        return Action::delete(
            $this->url('nowo_workflow_definition_delete', ['id' => $id]),
            $this->csrfTokenManager->getToken('delete' . $id)->getValue(),
            new Confirm(
                self::label('definition.confirm_delete'),
                self::label('definition.confirm_delete_message', ['%name%' => $definition->getName()]),
                self::label('action.delete'),
                cancelLabel: self::label('action.cancel'),
            ),
            self::label('action.delete'),
        );
    }

    private function createAction(): Action
    {
        return Action::create($this->url('nowo_workflow_definition_new'), self::label('nav.new_definition'));
    }

    /**
     * @return list<Crumb>
     */
    private function definitionCrumbs(WorkflowDefinition $definition, bool $linkDefinition): array
    {
        return [
            new Crumb(self::label('nav.dashboard'), $this->url('nowo_workflow_dashboard')),
            new Crumb(self::label('nav.definitions'), $this->url('nowo_workflow_definition_index')),
            new Crumb($definition->getName(), $linkDefinition ? $this->url('nowo_workflow_definition_show', ['id' => (int) $definition->getId()]) : null),
        ];
    }

    /**
     * @return list<DetailItem>
     */
    private function matchRuleItems(WorkflowDefinition $definition): array
    {
        if ($definition->isDefaultMatcher()) {
            return [new DetailItem(self::label('definition.match_default_label'), self::label('definition.match_default', ['%class%' => $definition->getSubjectClass()]))];
        }

        $items = [];
        foreach ($definition->getMatchRules() as $rule) {
            $items[] = new DetailItem($rule->getParameterKey(), $rule->getParameterValue());
        }

        return $items;
    }

    /**
     * @param array<string, int> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
