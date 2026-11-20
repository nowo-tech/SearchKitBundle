import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — the header search in situ: navbar, the demo page, the stretched field with grouped results and the
 * Symfony WebProfiler toolbar (full demo frame).
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

// The Symfony WebProfiler toolbar is not part of the demo platform: hide it in every capture.
test.beforeEach(async ({ page }) => {
  await page.addInitScript((css) => {
    const add = () => { const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', add); else add();
  }, '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}');
});

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.use({ viewport: { width: 1280, height: 800 } });

test.describe('feature screenshots', () => {
  test('overview — grouped results under the stretched field', async ({ page }) => {
    await page.goto('/en/login');
    await page.getByLabel('Username').fill('alice');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByTestId('nowo-search-toggle').click();
    await page.getByRole('combobox').fill('s');
    await page.getByRole('combobox').fill('sh');
    await expect(page.locator('[data-group="projects"]')).toBeVisible();
    await page.waitForTimeout(400); // stretch + dropdown animations
    await page.screenshot({ path: resolve(outDir, 'overview.png') });
  });
});
