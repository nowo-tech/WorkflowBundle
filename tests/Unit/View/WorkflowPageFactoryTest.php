<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\View;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\KpiTile;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Tests\Support\StubUrlGenerator;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;
use Nowo\WorkflowBundle\View\WorkflowPageFactory;
use PHPUnit\Framework\TestCase;

use function array_map;

final class WorkflowPageFactoryTest extends TestCase
{
    public function testLabelUsesTheBundleDomain(): void
    {
        $label = WorkflowPageFactory::label('kpi.enabled', ['%n%' => 1]);

        self::assertSame('kpi.enabled', $label->key);
        self::assertSame('NowoWorkflowBundle', $label->domain);
        self::assertSame(['%n%' => 1], $label->params);
    }

    public function testDashboardKpis(): void
    {
        $page = WorkflowPageFixtures::factory('layouts/admin.html.twig')->dashboard(5, 3);

        self::assertSame('NowoWorkflowBundle', $page->bundle);
        self::assertSame('layouts/admin.html.twig', $page->layout);
        self::assertSame([5, 3, 2], array_map(static fn (KpiTile $kpi): int|float|string => $kpi->value, $page->kpis));
        self::assertSame(['neutral', 'success', 'warning'], array_map(static fn (KpiTile $kpi): string => $kpi->variant, $page->kpis));
        self::assertTrue($page->kpis[0]->linkTile);
        self::assertSame('/generated/nowo_workflow_definition_index', $page->kpis[0]->href);
        self::assertSame('neutral', WorkflowPageFixtures::factory()->dashboard(2, 2)->kpis[2]->variant);
        self::assertSame('create', $page->header->actions[0]->kind);
    }

    public function testIndexPaginatesOnlyWithAPageSize(): void
    {
        $factory = WorkflowPageFixtures::factory();
        $order   = WorkflowPageFixtures::purchaseOrder();

        $paged = $factory->index(WorkflowPageFixtures::result([$order], 45, 2));
        self::assertNotNull($paged->pagination);
        self::assertSame(2, $paged->pagination->page);
        self::assertSame(3, $paged->pagination->totalPages);
        self::assertSame('nowo_workflow_definition_index', $paged->pagination->route);
        self::assertSame(['view', 'edit', 'delete'], array_map(static fn (Action $a): string => $a->kind, $paged->rowActions($order, 0)));

        self::assertNull($factory->index(WorkflowPageFixtures::result([$order], 1, 1, 0))->pagination);
    }

    public function testDeleteActionPostsTheCsrfTokenBehindAConfirm(): void
    {
        $action = WorkflowPageFixtures::factory()->deleteAction(WorkflowPageFixtures::purchaseOrder(7));

        self::assertTrue($action->isPost());
        self::assertSame('/generated/nowo_workflow_definition_delete', $action->href);
        self::assertSame('token-delete7', $action->csrfToken);
        self::assertNotNull($action->confirm);
        self::assertInstanceOf(Label::class, $action->confirm->message);
        self::assertSame(['%name%' => 'Purchase order approval'], $action->confirm->message->params);
    }

    public function testShowSectionsAndDefaultMatcher(): void
    {
        $factory = WorkflowPageFixtures::factory();

        $order = $factory->show(WorkflowPageFixtures::purchaseOrder());
        self::assertSame('Purchase order approval', $order->header->title);
        self::assertSame(['edit', 'delete'], array_map(static fn (Action $a): string => $a->kind, $order->header->actions));
        self::assertSame('primary', $order->header->actions[0]->variant);
        self::assertCount(3, $order->sections);
        self::assertSame('3 / 2', $order->sections[1]->items[3]->value);
        self::assertSame('department', $order->sections[2]->items[0]->label);
        self::assertCount(2, $order->meta);
        self::assertNull($order->header->breadcrumbs[2]->href);

        $review = $factory->show(WorkflowPageFixtures::documentReview());
        $item   = $review->sections[2]->items[0];
        self::assertInstanceOf(DetailItem::class, $item);
        self::assertInstanceOf(Label::class, $item->value);
        self::assertSame('definition.match_default', $item->value->key);
        self::assertSame('status.disabled', $review->sections[0]->items[2]->value instanceof Label ? $review->sections[0]->items[2]->value->key : null);
    }

    public function testNewFormPage(): void
    {
        $definition = WorkflowPageFixtures::emptyDefinition();
        $page       = WorkflowPageFixtures::factory()->newForm(WorkflowPageFixtures::formView($definition, WorkflowDefinitionFormSection::General));

        self::assertSame('/generated/nowo_workflow_definition_index', $page->cancelHref);
        self::assertInstanceOf(Label::class, $page->submitLabel);
        self::assertSame('action.create_continue', $page->submitLabel->key);
    }

    public function testEditPageHasOneTabPerSectionAndTheFormOnTheCurrentOne(): void
    {
        $definition = WorkflowPageFixtures::purchaseOrder();
        $form       = WorkflowPageFixtures::formView($definition, WorkflowDefinitionFormSection::Places);
        $page       = WorkflowPageFixtures::factory()->edit($definition, WorkflowDefinitionFormSection::Places, $form);

        self::assertSame('places', $page->current);
        self::assertSame(['general', 'match_rules', 'places', 'transitions'], array_map(static fn (SettingsSection $s): string => $s->id, $page->sections));
        self::assertSame([null, null, $form, null], array_map(static fn (SettingsSection $s): ?\Symfony\Component\Form\FormView => $s->form, $page->sections));
        self::assertSame('/generated/nowo_workflow_definition_edit_places', $page->sections[2]->href);
        self::assertSame('Purchase order approval · purchase_order', $page->header->intro);
        self::assertSame('/generated/nowo_workflow_definition_show', $page->header->breadcrumbs[2]->href);
    }

    public function testDeprecatedCssFrameworkFormThemesGoThroughTheViewModels(): void
    {
        $definition = WorkflowPageFixtures::purchaseOrder();
        $form       = WorkflowPageFixtures::formView($definition, WorkflowDefinitionFormSection::General);

        $default = WorkflowPageFixtures::factory();
        self::assertSame([], $default->newForm($form)->formThemes);
        self::assertSame([], $default->edit($definition, WorkflowDefinitionFormSection::General, $form)->sections[0]->formThemes);

        $legacy = new WorkflowPageFactory(new StubUrlGenerator(), WorkflowPageFixtures::csrf(), null, 'bootstrap5');
        self::assertSame(['bootstrap_5_layout.html.twig'], $legacy->newForm($form)->formThemes);
        $edit = $legacy->edit($definition, WorkflowDefinitionFormSection::General, $form);
        self::assertSame(['bootstrap_5_layout.html.twig'], $edit->sections[0]->formThemes);
        self::assertSame([], $edit->sections[1]->formThemes, 'only the section with the form');
    }

    public function testSectionHelpKeys(): void
    {
        self::assertSame(
            ['form.help.general', 'form.help.match_rules_list', 'form.help.places_list', 'form.help.transition_places'],
            array_map(static fn (WorkflowDefinitionFormSection $s): string => $s->helpKey(), WorkflowDefinitionFormSection::cases()),
        );
    }
}
