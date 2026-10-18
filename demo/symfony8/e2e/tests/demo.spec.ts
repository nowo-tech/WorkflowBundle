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

  test('show page: diagram dialogs and delete confirm', async ({ page }) => {
    const errors = collectErrors(page);
    const id = await definitionId(page, 'change_request_review');

    await page.goto(`/workflow/definitions/${id}`);
    await expect(page.getByTestId('workflow-flow-diagram')).toBeVisible();

    await page.locator('.nowo-workflow-diagram__state').first().click();
    const placeDialog = page.locator('dialog[open][data-nowo-ui-dialog]');
    await expect(placeDialog).toHaveCount(1);
    await placeDialog.locator('[data-workflow-dialog-swap]').first().click();
    await expect(page.locator('dialog[open]')).toHaveAttribute('id', /^workflow-transition-/);
    await page.keyboard.press('Escape');
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    await page.locator('[data-nowo-ui-region="header"] [data-nowo-confirm-open]').click();
    await expect(page.locator('dialog[open][data-nowo-ui-confirm]')).toBeVisible();
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

    expect(errors).toEqual([]);
  });
});
