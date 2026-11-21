import { type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 framing: padded union clip of the demo shell header → main (the Symfony toolbar is hidden).
 *
 * The viewport is grown to the content height (tall viewport) so the clip is never truncated.
 */
export async function frameShot(page: Page, path: string, opts: { minHeight?: number; pad?: number } = {}): Promise<void> {
  const pad = opts.pad ?? 0;
  const width = page.viewportSize()?.width ?? 1360;

  // The Symfony WebProfiler toolbar is not part of the demo platform: hide it so it never appears in a capture.
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });

  // Let a dialog finish its open animation before the capture.
  await page.waitForFunction(() => {
    const dialog = document.querySelector('dialog[open]');

    return !dialog || getComputedStyle(dialog).opacity === '1';
  }, undefined, { timeout: 5000 }).catch(() => undefined);
  await page.waitForTimeout(400);

  const contentBottom = await page.evaluate(() => {
    const main = document.querySelector('main');
    const bottom = main ? main.getBoundingClientRect().bottom + window.scrollY : document.documentElement.scrollHeight;
    return Math.ceil(bottom);
  });
  const height = Math.max(opts.minHeight ?? 720, contentBottom + pad);
  await page.setViewportSize({ width, height });
  await page.evaluate(() => window.scrollTo(0, 0));

  const clip = await page.evaluate((p) => {
    const els = [
      document.querySelector('body > nav, body > header, nav.navbar, header'),
      document.querySelector('main'),
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
