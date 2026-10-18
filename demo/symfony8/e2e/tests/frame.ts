import { expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 framing: padded union clip of the demo navbar/header → main → Symfony WebProfiler toolbar.
 *
 * The viewport is grown to the content height (tall viewport) so the fixed `.sf-toolbar` sits right below
 * `main` and the clip is never truncated.
 */
export async function frameShot(page: Page, path: string, opts: { minHeight?: number; pad?: number } = {}): Promise<void> {
  const pad = opts.pad ?? 0;
  const width = page.viewportSize()?.width ?? 1360;

  // Wait for the loaded toolbar (the request block), not the "Loading…" placeholder.
  if (await page.locator('.sf-toolbar').count()) {
    await expect(page.locator('.sf-toolbar .sf-toolbar-block-request').first()).toBeVisible();
  }

  const contentBottom = await page.evaluate(() => {
    const main = document.querySelector('main');
    const bottom = main ? main.getBoundingClientRect().bottom + window.scrollY : document.documentElement.scrollHeight;
    const toolbar = document.querySelector('.sf-toolbar') as HTMLElement | null;

    return Math.ceil(bottom + (toolbar ? toolbar.getBoundingClientRect().height : 0));
  });
  const height = Math.max(opts.minHeight ?? 720, contentBottom + pad);
  await page.setViewportSize({ width, height });
  await page.evaluate(() => window.scrollTo(0, 0));

  const clip = await page.evaluate((p) => {
    const els = [
      document.querySelector('body > nav, body > header, nav.navbar, header'),
      document.querySelector('main'),
      document.querySelector('.sf-toolbar'),
      document.querySelector('dialog[open]'),
    ].filter(Boolean) as Element[];
    let top = Infinity;
    let bottom = 0;
    for (const el of els) {
      const r = el.getBoundingClientRect();
      if (r.height === 0) continue;
      top = Math.min(top, r.top);
      bottom = Math.max(bottom, r.bottom);
    }

    return { x: 0, y: Math.max(0, top - p), width: window.innerWidth, height: Math.min(window.innerHeight, bottom + p) - Math.max(0, top - p) };
  }, pad);

  await page.screenshot({ path, clip });
}
