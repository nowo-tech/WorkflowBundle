<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\Test\RenderedPage;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;
use ReflectionProperty;
use Symfony\Component\Form\FormView;

use function dirname;

/**
 * REQ-UI-003 page contract: every Workflow admin page renders through a UiKit scaffold inside the stub host layout
 * and stub theme, without CDN assets, inline handlers, confirm(), framework classes outside theme slots or raw
 * translation keys.
 */
final class PageContractTest extends PageContractTestCase
{
    private const VIEWS = '@NowoWorkflowBundle/';

    public static function pageFixtures(): iterable
    {
        $pages     = WorkflowPageFixtures::factory();
        $presenter = new WorkflowGraphPresenter();
        $order     = WorkflowPageFixtures::purchaseOrder();
        $review    = WorkflowPageFixtures::documentReview();
        $empty     = WorkflowPageFixtures::emptyDefinition();

        yield 'dashboard' => new PageFixture(self::VIEWS . 'dashboard/index.html.twig', [
            'page'        => $pages->dashboard(25, 18),
            'definitions' => [$order, $review],
            'total'       => 25,
        ]);
        yield 'dashboard (empty)' => new PageFixture(self::VIEWS . 'dashboard/index.html.twig', [
            'page'        => $pages->dashboard(0, 0),
            'definitions' => [],
            'total'       => 0,
        ]);
        yield 'definitions list' => new PageFixture(self::VIEWS . 'workflow_definition/index.html.twig', [
            'page' => $pages->index(WorkflowPageFixtures::result([$order, $review, $empty], 45, 2)),
        ]);
        yield 'definitions list (empty)' => new PageFixture(self::VIEWS . 'workflow_definition/index.html.twig', [
            'page' => $pages->index(WorkflowPageFixtures::result([])),
        ]);
        yield 'show (state machine)' => new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => $pages->show($order),
            'definition' => $order,
            'graph'      => $presenter->present($order),
        ]);
        yield 'show (workflow with loop)' => new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => $pages->show($review),
            'definition' => $review,
            'graph'      => $presenter->present($review),
        ]);
        yield 'show (no places)' => new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => $pages->show($empty),
            'definition' => $empty,
            'graph'      => $presenter->present($empty),
        ]);
        yield 'new' => new PageFixture(self::VIEWS . 'workflow_definition/form.html.twig', [
            'page'    => $pages->newForm(WorkflowPageFixtures::formView(WorkflowPageFixtures::emptyDefinition(), WorkflowDefinitionFormSection::General)),
            'section' => WorkflowDefinitionFormSection::General,
        ]);
        yield 'new (errors)' => new PageFixture(self::VIEWS . 'workflow_definition/form.html.twig', [
            'page'    => $pages->newForm(WorkflowPageFixtures::formView(WorkflowPageFixtures::emptyDefinition(), WorkflowDefinitionFormSection::General, true)),
            'section' => WorkflowDefinitionFormSection::General,
        ]);
        foreach (WorkflowDefinitionFormSection::cases() as $section) {
            $definition = $section === WorkflowDefinitionFormSection::Transitions ? WorkflowPageFixtures::documentReview() : WorkflowPageFixtures::purchaseOrder();

            yield 'edit ' . $section->value => new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
                'page'       => $pages->edit($definition, $section, WorkflowPageFixtures::formView($definition, $section)),
                'definition' => $definition,
                'section'    => $section,
            ]);
        }
    }

    public function testFormPagesRenderEachFieldOnce(): void
    {
        $pages = WorkflowPageFixtures::factory();
        $order = WorkflowPageFixtures::purchaseOrder();

        $new = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/form.html.twig', [
            'page'    => $pages->newForm(WorkflowPageFixtures::formView(WorkflowPageFixtures::emptyDefinition(), WorkflowDefinitionFormSection::General)),
            'section' => WorkflowDefinitionFormSection::General,
        ]));
        $edit = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
            'page'       => $pages->edit($order, WorkflowDefinitionFormSection::General, WorkflowPageFixtures::formView($order, WorkflowDefinitionFormSection::General)),
            'definition' => $order,
            'section'    => WorkflowDefinitionFormSection::General,
        ]));

        foreach ([$new, $edit] as $page) {
            self::assertSame(1, substr_count($page->html, 'name="workflow_definition_form[name]"'));
            self::assertSame(1, substr_count($page->html, 'name="workflow_definition_form"'));
            self::assertStringContainsString('nowo-workflow-form-grid', $page->html);
        }
        self::assertStringContainsString('data-nowo-ui-region="tabs"', $edit->html);
        self::assertStringContainsString('/nowo_workflow_definition_show?id=1', $edit->html);
    }

    public function testDeleteGoesThroughTheUiKitConfirmDialog(): void
    {
        $order = WorkflowPageFixtures::purchaseOrder();
        $page  = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => WorkflowPageFixtures::factory()->show($order),
            'definition' => $order,
            'graph'      => (new WorkflowGraphPresenter())->present($order),
        ]));

        self::assertStringContainsString('data-nowo-ui-confirm', $page->html);
        self::assertStringContainsString('action="/generated/nowo_workflow_definition_delete"', $page->html);
        self::assertStringContainsString('value="token-delete1"', $page->html);
        self::assertStringContainsString('“Purchase order approval”', $page->html);
        self::assertStringNotContainsString('onsubmit', $page->html);
        self::assertSame(5, substr_count($page->html, 'data-workflow-dialog '), 'one dialog per place and per transition');
        self::assertStringContainsString('id="workflow-transition-1"', $page->html);
        self::assertStringContainsString('/assets/bundles/nowoworkflow/js/nowo-workflow.js', $page->html);
    }

    public function testCollectionEditorsShipPrototypeDialogsAndLocalAssets(): void
    {
        $pages  = WorkflowPageFixtures::factory();
        $review = WorkflowPageFixtures::documentReview();
        $order  = WorkflowPageFixtures::purchaseOrder();

        $transitions = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
            'page'       => $pages->edit($review, WorkflowDefinitionFormSection::Transitions, WorkflowPageFixtures::formView($review, WorkflowDefinitionFormSection::Transitions)),
            'definition' => $review,
            'section'    => WorkflowDefinitionFormSection::Transitions,
        ]));
        self::assertStringContainsString('data-workflow-collection="transitions"', $transitions->html);
        self::assertStringContainsString('data-prototype=', $transitions->html);
        self::assertStringContainsString('id="workflow-transitions-editor"', $transitions->html);
        self::assertStringContainsString('id="workflow-transitions-remove"', $transitions->html);
        self::assertStringContainsString('nowo-workflow-dialog--wide', $transitions->html);
        self::assertStringContainsString('&quot;legal_review&quot;', $transitions->html);
        self::assertStringContainsString('/assets/bundles/nowoworkflow/vendor/tom-select/tom-select.complete.min.js', $transitions->html);
        self::assertSame(3, substr_count($transitions->html, 'data-collection-entry='));

        $places = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
            'page'       => $pages->edit($order, WorkflowDefinitionFormSection::Places, WorkflowPageFixtures::formView($order, WorkflowDefinitionFormSection::Places)),
            'definition' => $order,
            'section'    => WorkflowDefinitionFormSection::Places,
        ]));
        self::assertStringContainsString('data-workflow-collection="places"', $places->html);
        self::assertStringNotContainsString('tom-select', $places->html);
    }

    public function testLegacyLayoutBridgeRendersTheCanonicalBlocksInTheOldLayout(): void
    {
        $order = WorkflowPageFixtures::purchaseOrder();
        $page  = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/index.html.twig', [
            'page'                          => WorkflowPageFixtures::factory(Configuration::LEGACY_LAYOUT_BRIDGE)->index(WorkflowPageFixtures::result([$order])),
            'nowo_workflow_layout_template' => 'legacy/base.html.twig',
        ]));

        self::assertStringContainsString('<title>Workflow definitions</title>', $page->html);
        self::assertStringContainsString('<main class="legacy">', $page->html);
        self::assertStringContainsString('data-nowo-ui-bundle="NowoWorkflowBundle"', $page->html);
        self::assertStringContainsString('Purchase order approval', $page->html);
        self::assertStringContainsString('/assets/bundles/nowoworkflow/css/nowo-workflow.css', $page->html);
        self::assertStringContainsString('/assets/js/nowo-ui-confirm.js', $page->html);
    }

    public function testDeprecatedIntegrateBaseBridgeRendersEveryCanonicalBlock(): void
    {
        $page = $this->renderPage(new PageFixture(self::VIEWS . 'dashboard/index.html.twig', [
            'page'        => WorkflowPageFixtures::factory('@NowoWorkflowBundle/layout_integrate_base.html.twig')->dashboard(1, 1),
            'definitions' => [WorkflowPageFixtures::purchaseOrder()],
            'total'       => 1,
        ]));

        self::assertStringContainsString('<title>Database-driven Symfony Workflows</title>', $page->html);
        self::assertStringContainsString('id="nowo-ui-content"', $page->html);
        self::assertStringContainsString('/assets/css/nowo-ui.css', $page->html);
        self::assertStringContainsString('/assets/js/nowo-ui-confirm.js', $page->html);
        self::assertStringContainsString('data-testid="workflow-dashboard-definitions"', $page->html);
    }

    public function testDeprecatedLocaleSwitcherRendersTheUiKitSwitcher(): void
    {
        $html = $this->createTwig()->render(self::VIEWS . '_locale_switcher.html.twig', ['nowo_workflow_locales' => ['en', 'es']]);

        self::assertStringContainsString('nowo-ui-locale-switcher', $html);
        self::assertStringContainsString('/nowo_workflow_locale_switch?_locale=es', $html);
        self::assertStringContainsString('Spanish', $html);
        self::assertSame('', trim($this->createTwig()->render(self::VIEWS . '_locale_switcher.html.twig', ['nowo_workflow_locales' => ['en']])));
    }

    protected static function inlineTemplates(): array
    {
        return [
            'legacy/base.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}<main class="legacy">{% block body %}{% endblock %}</main>{% block javascripts %}{% endblock %}',
            'base.html.twig'        => '<title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}{% block javascripts %}{% endblock %}',
        ];
    }

    /**
     * The base test case resets the fixtures' FormViews between renders but not the collection prototypes
     * ({@code vars.prototype}), which the collection editors render once per page.
     */
    protected function renderPage(PageFixture $fixture, string $framework = 'none'): RenderedPage
    {
        foreach ($fixture->context as $value) {
            if ($value instanceof SettingsPage || $value instanceof FormPage) {
                foreach ($value instanceof SettingsPage ? $value->sections : [$value] as $holder) {
                    $form = $holder->form ?? null;
                    if ($form instanceof FormView) {
                        self::resetPrototypes($form);
                    }
                }
            }
        }

        return parent::renderPage($fixture, $framework);
    }

    private static function resetPrototypes(FormView $view, bool $inPrototype = false): void
    {
        if ($inPrototype) {
            (new ReflectionProperty(FormView::class, 'rendered'))->setValue($view, false);
        }
        $prototype = $view->vars['prototype'] ?? null;
        if ($prototype instanceof FormView) {
            self::resetPrototypes($prototype, true);
        }
        foreach ($view->children as $child) {
            self::resetPrototypes($child, $inPrototype);
        }
    }

    protected static function templatePaths(): array
    {
        return ['NowoWorkflowBundle' => dirname(__DIR__, 2) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }
}
