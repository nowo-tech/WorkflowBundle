<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;

use function dirname;
use function fwrite;
use function sprintf;
use function strlen;

use const STDERR;

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
        $orderGraph = $presenter->present($order);
        yield 'flow detail (place)' => new PageFixture(self::VIEWS . 'workflow_definition/flow_detail.html.twig', [
            'page'       => $pages->flowDetail($order, $orderGraph['places'][0]['displayLabel']),
            'definition' => $order,
            'graph'      => $orderGraph,
            'kind'       => 'place',
            'item'       => $orderGraph['places'][0],
            'fragment'   => self::VIEWS . 'workflow_definition/_flow_place.html.twig',
        ]);
        yield 'flow detail (transition)' => new PageFixture(self::VIEWS . 'workflow_definition/flow_detail.html.twig', [
            'page'       => $pages->flowDetail($order, $orderGraph['transitions'][0]['displayLabel']),
            'definition' => $order,
            'graph'      => $orderGraph,
            'kind'       => 'transition',
            'item'       => $orderGraph['transitions'][0],
            'fragment'   => self::VIEWS . 'workflow_definition/_flow_transition.html.twig',
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
            self::assertMatchesRegularExpression(sprintf('#data-edge="%d" x="[\d.]+" y="[\d.]+" width="%d" height="28" data-from="%s" data-to="%s">\s*<a href="/nowo_workflow_definition_transition\?id=%d&amp;index=%d" class="nowo-workflow-diagram__transition" data-nowo-ui-dialog-open="workflow-flow-detail" data-nowo-ui-dialog-src="[^"]+" data-nowo-ui-dialog-form data-nowo-ui-dialog-title="[^"]+" title="[^"]+ → %s">%s</a>#u', $i, $edge['labelWidth'], $edge['fromPlace'], $edge['toPlace'], $definition->getId(), $edge['transitionIndex'], ucfirst(str_replace('_', ' ', $edge['toPlace'])), $edge['transitionLabel']), $html);
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
        // Lazy dialogs (2.3): the delete confirm plus ONE empty flow dialog, whatever the size of the graph.
        self::assertSame(1, substr_count($page->html, 'data-nowo-ui-dialog aria-labelledby'), 'one shared flow dialog');
        self::assertSame(1, substr_count($page->html, 'id="workflow-flow-detail"'));
        self::assertMatchesRegularExpression('#<div class="nowo-ui-dialog__body[^"]*"[^>]*data-nowo-ui-dialog-body></div>#', $page->html, 'the shell is empty');
        self::assertStringNotContainsString('id="workflow-place-', $page->html);
        self::assertStringNotContainsString('id="workflow-transition-', $page->html);
        self::assertStringNotContainsString('nowo-workflow-dialog__list', $page->html, 'no place detail before it is opened');
        self::assertStringNotContainsString('data-nowo-ui-dialog-replace', $page->html);
        // Each place / transition is a real link to its own page (works without JavaScript) that the dialog loads.
        self::assertStringContainsString('<a href="/nowo_workflow_definition_place?id=1&amp;index=0" class="nowo-workflow-diagram__state nowo-workflow-diagram__state--initial" data-nowo-ui-dialog-open="workflow-flow-detail" data-nowo-ui-dialog-src="/nowo_workflow_definition_place?id=1&amp;index=0" data-nowo-ui-dialog-form data-nowo-ui-dialog-title="Draft">', $page->html);
        self::assertStringContainsString('href="/nowo_workflow_definition_transition?id=1&amp;index=1"', $page->html);
        self::assertStringNotContainsString('<button type="button" class="nowo-workflow-diagram__', $page->html);
        self::assertStringNotContainsString('&lt;span', $page->html);
        self::assertStringContainsString('/assets/js/nowo-ui-dialog.js', $page->html);
        self::assertStringContainsString('/assets/js/nowo-workflow.js', $page->html);
    }

    public function testFlowDetailPageCarriesThePlaceAndLinksItsTransitions(): void
    {
        $order = WorkflowPageFixtures::purchaseOrder();
        $graph = (new WorkflowGraphPresenter())->present($order);
        $page  = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/flow_detail.html.twig', [
            'page'       => WorkflowPageFixtures::factory()->flowDetail($order, $graph['places'][0]['displayLabel']),
            'definition' => $order,
            'graph'      => $graph,
            'kind'       => 'place',
            'item'       => $graph['places'][0],
            'fragment'   => self::VIEWS . 'workflow_definition/_flow_place.html.twig',
        ]));

        self::assertStringContainsString('data-testid="workflow-flow-place" data-place="draft"', $page->html);
        self::assertMatchesRegularExpression('#<span class="nowo-ui-badge[^"]*">Initial</span>#', $page->html, 'initial badge');
        self::assertMatchesRegularExpression('#<a href="/nowo_workflow_definition_transition\?id=1&amp;index=\d+" class="[^"]*" data-nowo-ui-dialog-open="workflow-flow-detail"#', $page->html, 'a transition loads into the same dialog');
        self::assertStringContainsString('href="/nowo_workflow_definition_show?id=1"', $page->html, 'full page: back to the definition');
        self::assertStringNotContainsString('data-nowo-ui-dialog-close>', $page->html, 'no dialog Close on the full page');
    }

    /**
     * Lazy dialogs (2.3), the owner rule "every dialog loads its content on demand": a definition with 20 places and
     * 20 transitions renders no place / transition detail and one dialog. Sizes printed for the PR.
     */
    public function testLargeDefinitionRendersNoDialogBodies(): void
    {
        $definition = WorkflowPageFixtures::largeDefinition(20);
        $graph      = (new WorkflowGraphPresenter())->present($definition);
        $page       = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/show.html.twig', [
            'page'       => WorkflowPageFixtures::factory()->show($definition),
            'definition' => $definition,
            'graph'      => $graph,
        ]));

        fwrite(STDERR, sprintf("\n[lazy-dialogs] workflow show, 20 places + 20 transitions: %d bytes\n", strlen($page->html)));
        self::assertCount(20, $graph['places']);
        self::assertCount(20, $graph['transitions']);
        self::assertSame(2, substr_count($page->html, '<dialog'), 'the delete confirm and the one flow dialog');
        self::assertSame(0, substr_count($page->html, 'nowo-workflow-dialog__list'));
        self::assertSame(0, substr_count($page->html, 'diagram.modal'), 'no detail label key either');
        self::assertSame(20, substr_count($page->html, 'href="/nowo_workflow_definition_place?'));
        self::assertGreaterThanOrEqual(20, substr_count($page->html, 'href="/nowo_workflow_definition_transition?'));
    }

    /**
     * Lazy dialogs (2.3) audit of the collection editor: the hidden entries are the fields of the ONE section form the
     * page posts (one form, one CSRF token, one submit), not a form per row; the editor is one dialog whose body is
     * empty until an entry's fields move into it, and new entries clone one `<template>` prototype. So 20 transitions
     * give 2 dialogs (editor + remove confirm), 1 form and 1 prototype.
     */
    public function testLargeCollectionEditorIsOneFormWithOneEditorDialog(): void
    {
        $render = function (int $places): string {
            $definition = WorkflowPageFixtures::largeDefinition($places);

            return $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
                'page'       => WorkflowPageFixtures::factory()->edit($definition, WorkflowDefinitionFormSection::Transitions, WorkflowPageFixtures::formView($definition, WorkflowDefinitionFormSection::Transitions)),
                'definition' => $definition,
                'section'    => WorkflowDefinitionFormSection::Transitions,
            ]))->html;
        };
        $small = $render(2);
        $html  = $render(20);
        fwrite(STDERR, sprintf("\n[lazy-dialogs] workflow edit transitions, 20 entries: %d bytes\n", strlen($html)));

        self::assertSame(1, substr_count($html, 'id="workflow-definition-form"'), 'one section form');
        self::assertSame(substr_count($small, '<form'), substr_count($html, '<form'), 'no form per entry');
        self::assertSame(substr_count($small, '<dialog'), substr_count($html, '<dialog'), 'no dialog per entry');
        self::assertSame(1, substr_count($html, '<dialog id="workflow-transitions-editor"'));
        self::assertSame(1, substr_count($html, '<template data-collection-prototype>'));
        self::assertSame(20, substr_count($html, 'data-collection-entry='));
        self::assertStringContainsString('<div data-dialog-body></div>', $html, 'the editor body is empty until an entry is opened');
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
        self::assertStringContainsString('<template data-collection-prototype>', $transitions->html);
        self::assertStringNotContainsString('data-prototype=', $transitions->html);
        self::assertStringContainsString('data-code-class="nowo-ui-code', $transitions->html, 'JS-built code cells use the UiKit code slot');
        self::assertStringContainsString('id="workflow-transitions-editor"', $transitions->html);
        self::assertStringContainsString('id="workflow-transitions-remove"', $transitions->html);
        self::assertStringContainsString('nowo-ui-dialog--lg', $transitions->html);
        self::assertStringContainsString('data-title-edit="Edit transition"', $transitions->html);
        self::assertStringContainsString('nowo-ui-input--full nowo-place-multiselect', $transitions->html);
        self::assertStringContainsString('&quot;legal_review&quot;', $transitions->html);
        self::assertStringContainsString('/assets/vendor/tom-select/tom-select.complete.min.js', $transitions->html);
        self::assertSame(3, substr_count($transitions->html, 'data-collection-entry='));

        $places = $this->renderPage(new PageFixture(self::VIEWS . 'workflow_definition/edit.html.twig', [
            'page'       => $pages->edit($order, WorkflowDefinitionFormSection::Places, WorkflowPageFixtures::formView($order, WorkflowDefinitionFormSection::Places)),
            'definition' => $order,
            'section'    => WorkflowDefinitionFormSection::Places,
        ]));
        self::assertStringContainsString('data-workflow-collection="places"', $places->html);
        self::assertStringNotContainsString('tom-select', $places->html);
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
