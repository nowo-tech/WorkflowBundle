import { test, expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 — Workflow admin UI on the UiKit 2.1 page contract (REQ-UI-003): every page renders through the
 * demo host layout, dialogs are native <dialog>s (UiKit `dialog` partial; no Bootstrap JS, no confirm()), the
 * collection editors work and no browser error is logged. The demo must be seeded (make reset-demo) before running.
 */
function collectErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    // The WebProfiler toolbar may load before its profile is stored (404 on /_wdt): not a page error.
    if (message.type() === 'error' && !/_wdt|favicon/.test(message.location().url)) {
      errors.push(message.text());
    }
  });
  page.on('response', (response) => {
    if (response.status() >= 400 && !/_wdt|favicon/.test(response.url())) {
      errors.push(`${response.status()} ${response.url()}`);
    }
  });

  return errors;
}

async function definitionId(page: Page, slug: string): Promise<string> {
  await page.goto(`/workflow/definitions/by-slug/${slug}`);
  const href = await page.locator('[data-nowo-ui-region="header"] a[href*="/edit/general"]').first().getAttribute('href');

  return href!.match(/definitions\/(\d+)\//)![1];
}

test.describe('workflow admin (UiKit page contract)', () => {
  test('dashboard and definitions list', async ({ page }) => {
    const errors = collectErrors(page);

    await page.goto('/workflow/');
    await expect(page.getByTestId('workflow-dashboard')).toBeVisible();
    await expect(page.locator('.nowo-ui-kpi')).toHaveCount(3);
    await expect(page.getByTestId('workflow-dashboard-definitions').locator('tbody tr').first()).toBeVisible();

    await page.goto('/workflow/definitions');
    await expect(page.getByTestId('workflow-definitions')).toBeVisible();
    await expect(page.locator('[data-testid="workflow-definitions"] tbody tr')).toHaveCount(10);

    expect(errors).toEqual([]);
  });

  test('show page: diagram dialog loads on demand, and delete confirm', async ({ page }) => {
    const errors = collectErrors(page);
    const id = await definitionId(page, 'change_request_review');

    await page.goto(`/workflow/definitions/${id}`);
    await expect(page.getByTestId('workflow-flow-diagram')).toBeVisible();

    // Lazy dialogs (2.3): ONE empty shell, nothing of a place / transition in the page before it is opened.
    const dialog = page.locator('dialog#workflow-flow-detail');
    await expect(dialog).toHaveCount(1);
    await expect(page.locator('dialog[data-nowo-ui-dialog]')).toHaveCount(1);
    await expect(dialog.locator('[data-nowo-ui-dialog-body]')).toBeEmpty();
    await expect(page.locator('[data-testid="workflow-flow-place"], [data-testid="workflow-flow-transition"]')).toHaveCount(0);

    // The opener is a real link; the dialog fetches its fragment with X-Nowo-Dialog: 1.
    const state = page.locator('a.nowo-workflow-diagram__state').first();
    await expect(state).toHaveAttribute('href', new RegExp(`/workflow/definitions/${id}/places/0$`));
    const fragment = page.waitForRequest((request) => request.url().endsWith(`/definitions/${id}/places/0`) && request.headers()['x-nowo-dialog'] === '1');
    await state.click();
    await fragment;
    await expect(dialog).toHaveAttribute('open', '');
    const place = dialog.getByTestId('workflow-flow-place');
    await expect(place).toBeVisible();
    await expect(dialog.locator('#workflow-flow-detail-title')).toHaveText(((await state.locator('.nowo-workflow-diagram__state-title').textContent()) ?? '').trim());
    await expect(place.locator('h1, header, nav')).toHaveCount(0); // a fragment, not the page

    // A transition of the place loads into the same dialog.
    await place.locator('.nowo-workflow-dialog__list a').first().click();
    await expect(dialog.getByTestId('workflow-flow-transition')).toBeVisible();
    await expect(page.locator('dialog[open]:not([data-nowo-ui-closing])')).toHaveCount(1);
    await dialog.locator('[data-nowo-ui-dialog-footer] [data-nowo-ui-dialog-close]').click();
    await expect(page.locator('dialog[open]:not([data-nowo-ui-closing])')).toHaveCount(0);
    await expect(dialog.locator('[data-nowo-ui-dialog-body] [data-testid]')).toHaveCount(0); // emptied again on close
    await expect(state).toBeFocused(); // focus back to the opener

    await page.locator('[data-nowo-ui-region="header"] [data-nowo-confirm-open]').click();
    await expect(page.locator('dialog[open][data-nowo-ui-confirm]')).toBeVisible();
    await page.keyboard.press('Escape');

    expect(errors).toEqual([]);
  });

  test('flow detail without JavaScript: the opener link is the full page', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    const id = await definitionId(page, 'change_request_review');
    await page.goto(`/workflow/definitions/${id}`);
    await page.locator('a.nowo-workflow-diagram__state').first().click();
    await expect(page).toHaveURL(new RegExp(`/workflow/definitions/${id}/places/0$`));
    await expect(page.getByTestId('workflow-flow-detail')).toBeVisible();
    await expect(page.getByTestId('workflow-flow-place')).toBeVisible();
    await page.getByRole('link', { name: 'Back to the workflow' }).click();
    await expect(page).toHaveURL(new RegExp(`/workflow/definitions/${id}$`));
    await context.close();
  });

  test('flow diagram: every connector ends at its own target, only true loops are listed', async ({ page }) => {
    const errors = collectErrors(page);
    const id = await definitionId(page, 'change_request_review');
    await page.goto(`/workflow/definitions/${id}`);

    const svg = page.getByTestId('workflow-flow-svg');
    await expect(svg).toBeVisible();
    await expect(svg.locator('.nowo-workflow-diagram__edge')).toHaveCount(6);

    // Each connector ends at the left edge, vertical middle, of the place box it names in data-to.
    const misses = await svg.evaluate((root) => {
      const out: string[] = [];
      root.querySelectorAll<SVGPathElement>('.nowo-workflow-diagram__edge').forEach((path) => {
        const end = path.getPointAtLength(path.getTotalLength());
        const box = root.querySelector<SVGForeignObjectElement>(`.nowo-workflow-diagram__box[data-place="${path.dataset.to}"]`)!;
        const x = box.x.baseVal.value;
        const middle = box.y.baseVal.value + box.height.baseVal.value / 2;
        if (Math.abs(end.x - x) > 0.5 || Math.abs(end.y - middle) > 0.5) {
          out.push(`${path.dataset.from} → ${path.dataset.to}`);
        }
      });

      return out;
    });
    expect(misses).toEqual([]);

    const label = (text: string) => svg.locator('.nowo-workflow-diagram__label-box').filter({ has: page.getByRole('link', { name: text, exact: true }) });
    await expect(label('Submit for review')).toHaveAttribute('data-to', 'review');
    await expect(label('Approve')).toHaveAttribute('data-to', 'approved');
    await expect(label('Request changes')).toHaveAttribute('data-to', 'changes_needed');

    const loops = page.getByTestId('workflow-flow-back-edges').getByRole('link');
    await expect(loops).toHaveCount(2);
    await expect(loops).toContainText(['Resubmit', 'Reopen']);

    await label('Approve').getByRole('link').hover();
    await expect(svg.locator('.nowo-workflow-diagram__edge.is-active')).toHaveAttribute('data-to', 'approved');
    await label('Approve').getByRole('link').click();
    await expect(page.locator('dialog[open]')).toHaveAttribute('id', 'workflow-flow-detail');
    await expect(page.locator('dialog[open]').getByTestId('workflow-flow-transition')).toHaveAttribute('data-transition', 'approve');
    await page.keyboard.press('Escape');

    expect(errors).toEqual([]);
  });

  test('places editor: add, validate, remove and save', async ({ page }) => {
    const errors = collectErrors(page);
    const id = await definitionId(page, 'invoice_processing');
    await page.goto(`/workflow/definitions/${id}/edit/places`);

    const rows = page.locator('[data-workflow-collection="places"] tbody tr');
    const before = await rows.count();
    const editor = page.locator('#workflow-places-editor');

    await page.locator('[data-collection-add]').click();
    await editor.locator('input[name$="[name]"]').fill('e2e_archived');
    await editor.locator('[data-dialog-apply]').click();
    await expect(editor).toHaveAttribute('open', ''); // sort order is required: the dialog stays open
    await editor.locator('input[name$="[sortOrder]"]').fill('9');
    await editor.locator('[data-dialog-apply]').click();
    await expect(rows).toHaveCount(before + 1);

    await rows.last().locator('[data-row-delete]').click();
    await page.locator('#workflow-places-remove [data-collection-remove-confirm]').click();
    await expect(rows).toHaveCount(before);

    await page.locator('[data-collection-add]').click();
    await editor.locator('input[name$="[name]"]').fill('e2e_archived');
    await editor.locator('input[name$="[sortOrder]"]').fill('9');
    await editor.locator('[data-dialog-apply]').click();
    await page.locator('#workflow-definition-form button[type="submit"]').click();
    await expect(page.locator('.nowo-ui-flash')).toBeVisible();
    await expect(rows).toHaveCount(before + 1);

    // Leave the demo data as seeded.
    await rows.last().locator('[data-row-delete]').click();
    await page.locator('#workflow-places-remove [data-collection-remove-confirm]').click();
    await page.locator('#workflow-definition-form button[type="submit"]').click();
    await expect(rows).toHaveCount(before);

    expect(errors).toEqual([]);
  });

  test('transitions editor uses the bundled TomSelect', async ({ page }) => {
    const errors = collectErrors(page);
    const id = await definitionId(page, 'change_request_review');
    await page.goto(`/workflow/definitions/${id}/edit/transitions`);

    await page.locator('[data-workflow-collection="transitions"] tbody tr').first().locator('[data-row-edit]').click();
    await expect(page.locator('#workflow-transitions-editor[open] .ts-control')).toHaveCount(2);
    await page.locator('#workflow-transitions-editor [data-dialog-cancel]').click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    // The UiKit dialog header close button discards the entry like Cancel / Esc.
    await page.locator('[data-workflow-collection="transitions"] tbody tr').first().locator('[data-row-edit]').click();
    await page.locator('#workflow-transitions-editor[open] [data-nowo-ui-dialog-close]').click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await expect(page.locator('#workflow-transitions-editor [data-dialog-body]')).toBeEmpty();

    // New entries come from <template data-collection-prototype> (no innerHTML): numbered fields, TomSelect on both lists.
    const collection = page.locator('[data-workflow-collection="transitions"]');
    const index = await collection.getAttribute('data-index');
    await collection.locator('[data-collection-add]').click();
    const editor = page.locator('#workflow-transitions-editor[open]');
    await expect(editor.locator('.ts-control')).toHaveCount(2);
    await expect(editor.locator(`input[name$="[${index}][name]"]`)).toHaveCount(1);
    await expect(editor.locator('[name*="__name__"], [id*="__name__"]')).toHaveCount(0);
    await page.locator('#workflow-transitions-editor [data-dialog-cancel]').click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    expect(errors).toEqual([]);
  });
});
