import { test, expect, type Page } from '@playwright/test';

/**
 * Spec 240 "search everywhere" in the demo: the palette (">" for actions), registry pages found by keywords in
 * another locale, typed filter chips, the full results page and revalidated recents — always as the signed-in user may
 * see them (alice: admin, every project; bob: public projects only).
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-masthead')).toContainText(username);
  await page.evaluate(() => document.querySelectorAll('.sf-toolbar, .sf-minitoolbar').forEach((el) => el.remove()));
}

async function openSearch(page: Page) {
  await page.getByTestId('nowo-search-toggle').click();
  return page.getByTestId('nowo-search').getByRole('combobox');
}

test.describe('search everywhere (spec 240)', () => {
  test('">" lists only the actions the viewer may open', async ({ page }) => {
    await signIn(page, 'alice');
    const field = await openSearch(page);
    await field.fill('>');
    const popover = page.getByTestId('nowo-search-popover');
    const actions = popover.locator('[data-group="actions"]');
    await expect(actions.getByRole('option', { name: /Open security settings/ })).toBeVisible();
    await expect(actions.getByRole('option', { name: /Manage users/ })).toBeVisible();
    await expect(popover.locator('[data-group="projects"]')).toHaveCount(0);
    await expect(actions.locator('.nowo-search__option-shortcut').first()).toHaveText('g s');

    await page.goto('/en/logout');
    await signIn(page, 'bob');
    const bobField = await openSearch(page);
    await bobField.fill('>');
    await expect(page.getByTestId('nowo-search-popover').getByRole('option', { name: /Open security settings/ })).toBeVisible();
    await expect(page.getByTestId('nowo-search-popover').getByRole('option', { name: /Manage users/ })).toHaveCount(0);
  });

  test('finds a settings page by a keyword of another locale, never one the viewer may not open', async ({ page }) => {
    await signIn(page, 'bob');
    const field = await openSearch(page);
    await field.fill('contraseña');
    const pages = page.getByTestId('nowo-search-popover').locator('[data-group="pages"]');
    await expect(pages.getByRole('option', { name: /Security/ })).toBeVisible();
    await field.fill('invoice');
    await expect(page.getByTestId('nowo-search-popover')).not.toContainText('Billing');
    await field.fill('contraseña');
    await expect(pages.getByRole('option', { name: /Security/ })).toBeVisible();
    await field.press('Enter');
    await expect(page).toHaveURL(/\/en\/settings\/security$/);
  });

  test('typed filters become chips that remove themselves on click', async ({ page }) => {
    await signIn(page, 'alice');
    const field = await openSearch(page);
    await field.fill('checkout is:open ');
    const chip = page.locator('.nowo-search__chip');
    await expect(chip).toHaveText('is:open');
    const issues = page.getByTestId('nowo-search-popover').locator('[data-group="issues"]');
    await expect(issues.getByRole('option')).toHaveCount(2);
    await expect(issues).not.toContainText('webhook');
    await chip.click();
    await expect(field).toHaveValue('checkout');
    await expect(page.locator('.nowo-search__chip')).toHaveCount(0);
  });

  test('"See all results" opens the results page with tabs, pages and only visible rows', async ({ page }) => {
    await signIn(page, 'bob');
    const field = await openSearch(page);
    await field.fill('checkout');
    const seeAll = page.getByTestId('nowo-search-popover').locator('[data-group="see-all"] [role="option"]');
    await expect(seeAll).toHaveText(/See all results/);
    await seeAll.click();
    await expect(page).toHaveURL(/\/search\/results\?q=checkout/);
    const results = page.getByTestId('nowo-search-results');
    await expect(results).toBeVisible();
    await expect(page.getByTestId('nowo-search-results-tabs')).toContainText('Issues (2)');
    await expect(results).not.toContainText('webhook');
    await expect(results).not.toContainText('Billing service');
    await page.getByTestId('nowo-search-results-tabs').getByRole('link', { name: /Issues/ }).click();
    await expect(page).toHaveURL(/group=issues/);
    await expect(results.locator('.nowo-search-results__row')).toHaveCount(2);
  });

  test('recents keep references only and are checked again when shown', async ({ page }) => {
    await signIn(page, 'alice');
    const field = await openSearch(page);
    await field.fill('refund');
    await page.getByTestId('nowo-search-popover').getByRole('option', { name: /Refund invoices/ }).click();
    await expect(page).toHaveURL(/\/en\/issues\/4$/);

    const stored = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('nowo.search.recent')).map((key) => localStorage.getItem(key) ?? ''));
    expect(stored.join('')).toContain('/en/issues/4');
    expect(stored.join('')).not.toContain('Refund');

    await openSearch(page);
    await expect(page.getByTestId('nowo-search-popover').locator('[data-group="recent"]')).toContainText('Refund invoices miss the tax line');
  });
});
