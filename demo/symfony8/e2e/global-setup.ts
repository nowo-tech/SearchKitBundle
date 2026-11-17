import { chromium, type FullConfig } from '@playwright/test';

/**
 * Warms the demo before the first test (audit SPd-I2): a FrankenPHP worker that was just started compiles the
 * container, Twig templates and translations on its first requests, which made the first test time out on a cold
 * stack. Waits until the landing page answers, loads the public pages, then signs in once and runs one search so the
 * signed-in templates and the search endpoint are compiled too.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = String(config.projects[0]?.use.baseURL ?? 'http://127.0.0.1:8157');
  const deadline = Date.now() + 60_000;
  for (;;) {
    try {
      const response = await fetch(`${baseURL}/en`);
      if (response.ok) {
        break;
      }
    } catch {
      // Not up yet.
    }
    if (Date.now() > deadline) {
      throw new Error(`The demo did not answer on ${baseURL}/en within 60 s.`);
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  for (const path of ['/en/login', '/es', '/en/docs/installation']) {
    await fetch(`${baseURL}${path}`).catch(() => undefined);
  }
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ baseURL });
    await page.goto('/en/login');
    await page.getByLabel('Username').fill('alice');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/en$/, { timeout: 30_000 });
    await page.request.get('/search?q=shop');
    await page.goto('/en/projects/shop');
  } catch {
    // Warming is best effort: the tests report real failures.
  } finally {
    await browser.close();
  }
}
