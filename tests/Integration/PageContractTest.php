<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;

use function dirname;
use function sprintf;

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
        $branching = WorkflowPageFixtures::changeRequestReview();
        yield 'show (branching state machine)' => new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => $pages->show($branching),
            'definition' => $branching,
            'graph'      => $presenter->present($branching),
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

    public function testDefinitionsListEndAlignsNumbersAndPaintsTheDefaultBadgeNeutral(): void
    {
        $page = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/index.html.twig', [
            'page' => WorkflowPageFixtures::factory()->index(WorkflowPageFixtures::result([WorkflowPageFixtures::purchaseOrder(), WorkflowPageFixtures::documentReview()])),
        ]));

        foreach (['Match rules', 'Priority', 'Places', 'Transitions'] as $label) {
            self::assertMatchesRegularExpression(sprintf('#<th scope="col" class="[^"]*nowo-ui-cell--end"[^>]*>\s*%s#', $label), $page->html, $label . ' header');
            self::assertSame(2, substr_count($page->html, sprintf('nowo-ui-cell--end" data-label="%s"', $label)), $label . ' cells');
        }
        self::assertMatchesRegularExpression('#<span class="nowo-ui-badge nowo-ui-badge--neutral[^"]*">Default</span>#', $page->html);
        self::assertStringNotContainsString('nowo-ui-badge--info', $page->html);
        self::assertStringContainsString('<code class="nowo-ui-code', $page->html);
    }

    public function testBranchingFlowDiagramDrawsOneConnectorPerEdgeToItsTarget(): void
    {
        $definition = WorkflowPageFixtures::changeRequestReview();
        $graph      = (new WorkflowGraphPresenter())->present($definition);
        $page       = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => WorkflowPageFixtures::factory()->show($definition),
            'definition' => $definition,
            'graph'      => $graph,
        ]));
        $html = $page->html;

        self::assertStringContainsString('data-testid="workflow-flow-svg"', $html);
        self::assertSame(6, substr_count($html, 'class="nowo-workflow-diagram__edge"'), 'one connector per forward edge');
        self::assertSame(6, substr_count($html, 'class="nowo-workflow-diagram__box"'), 'one box per place');

        self::assertNotNull($graph['layout']['diagram']);
        foreach ($graph['layout']['diagram']['edges'] as $i => $edge) {
            // The connector, its label and the label's title all name the same target place.
            self::assertMatchesRegularExpression(sprintf('#<path class="nowo-workflow-diagram__edge" data-edge="%d" d="%s" marker-end="[^"]+" data-from="%s" data-to="%s"#', $i, preg_quote($edge['path'], '#'), $edge['fromPlace'], $edge['toPlace']), $html);
            self::assertMatchesRegularExpression(sprintf('#data-edge="%d" x="[\d.]+" y="[\d.]+" width="%d" height="28" data-from="%s" data-to="%s">\s*<button type="button" class="nowo-workflow-diagram__transition" data-nowo-ui-dialog-open="workflow-transition-%d" title="[^"]+ → %s">%s</button>#u', $i, $edge['labelWidth'], $edge['fromPlace'], $edge['toPlace'], $edge['transitionIndex'], ucfirst(str_replace('_', ' ', $edge['toPlace'])), $edge['transitionLabel']), $html);
        }

        self::assertMatchesRegularExpression('#data-testid="workflow-flow-back-edges"#', $html);
        self::assertSame(1, preg_match('#data-testid="workflow-flow-back-edges".*?</div>\s*</div>#s', $html, $loops));
        self::assertSame(2, substr_count($loops[0], 'data-nowo-ui-dialog-open'), 'only Resubmit and Reopen are loops');
        self::assertStringContainsString('Resubmit', $loops[0]);
        self::assertStringContainsString('Reopen', $loops[0]);
        self::assertStringNotContainsString('Cancel', $loops[0]);
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
        self::assertSame(5, substr_count($page->html, 'data-nowo-ui-dialog aria-labelledby'), 'one UiKit dialog per place and per transition');
        self::assertStringContainsString('data-nowo-ui-dialog-open="workflow-place-0"', $page->html);
        self::assertStringContainsString('data-nowo-ui-dialog-replace', $page->html);
        self::assertStringNotContainsString('data-workflow-dialog-swap', $page->html);
        self::assertMatchesRegularExpression('#id="workflow-place-0-title"[^>]*>\s*Draft\s*<span class="nowo-ui-badge#', $page->html, 'initial badge in the title');
        self::assertStringNotContainsString('&lt;span', $page->html);
        self::assertStringContainsString('/assets/js/nowo-ui-dialog.js', $page->html);
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
        self::assertStringContainsString('data-code-class="nowo-ui-code', $transitions->html, 'JS-built code cells use the UiKit code slot');
        self::assertStringContainsString('id="workflow-transitions-editor"', $transitions->html);
        self::assertStringContainsString('id="workflow-transitions-remove"', $transitions->html);
        self::assertStringContainsString('nowo-ui-dialog--lg', $transitions->html);
        self::assertStringContainsString('data-title-edit="Edit transition"', $transitions->html);
        self::assertStringContainsString('nowo-ui-input--full nowo-place-multiselect', $transitions->html);
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
