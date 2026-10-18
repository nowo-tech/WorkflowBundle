import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { frameShot } from './frame';

/**
 * REQ-DEMO-013 — full demo frame (navbar + Workflow admin page + WebProfiler toolbar) of the main screens,
 * captured as a padded union clip with a tall viewport (see frame.ts).
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.use({ viewport: { width: 1360, height: 1000 } });

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

async function definitionId(page: Page, slug: string): Promise<string> {
  await page.goto(`/workflow/definitions/by-slug/${slug}`);
  const href = await page.locator('[data-nowo-ui-region="header"] a[href*="/edit/general"]').first().getAttribute('href');

  return href!.match(/definitions\/(\d+)\//)![1];
}

test('definitions list', async ({ page }) => {
  await page.goto('/workflow/definitions');
  await expect(page.getByTestId('workflow-definitions')).toBeVisible();
  await frameShot(page, resolve(outDir, 'definitions.png'));
});

test('definition with flow diagram', async ({ page }) => {
  const id = await definitionId(page, 'change_request_review');
  await page.goto(`/workflow/definitions/${id}`);
  await expect(page.getByTestId('workflow-flow-diagram')).toBeVisible();
  await frameShot(page, resolve(outDir, 'flow-diagram.png'));
});

test('transitions editor dialog', async ({ page }) => {
  const id = await definitionId(page, 'change_request_review');
  await page.goto(`/workflow/definitions/${id}/edit/transitions`);
  await page.locator('[data-workflow-collection="transitions"] tbody tr').nth(3).locator('[data-row-edit]').click();
  await expect(page.locator('#workflow-transitions-editor[open] .ts-control').first()).toBeVisible();
  await frameShot(page, resolve(outDir, 'transition-editor.png'));
});
