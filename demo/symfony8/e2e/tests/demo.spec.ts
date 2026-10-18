import { test, expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy-path e2e against the Search Kit demo: open (button, Ctrl K, "/"), grouped permission-aware
 * results, keyboard navigation, the scope chip and the full-width field on phones.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
  // The Symfony toolbar can cover the dropdown on small screens.
  await page.evaluate(() => document.querySelectorAll('.sf-toolbar, .sf-minitoolbar').forEach((el) => el.remove()));
}

test.describe('search kit demo', () => {
  test('guests see the landing page without the search box', async ({ page }) => {
    const response = await page.goto('/en');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1')).toContainText('Search Kit Bundle');
    await expect(page.getByTestId('nowo-search')).toHaveCount(0);
  });

  test('stretches from the magnifier, lists grouped results and opens one with the keyboard', async ({ page }) => {
    await signIn(page, 'alice');
    const search = page.getByTestId('nowo-search');
    const field = search.getByRole('combobox');
    await expect(field).not.toBeVisible();

    // Optional UiKit integration (REQ-UI-003): the host class of the btn_icon slot reaches the toggle.
    await expect(page.getByTestId('nowo-search-toggle')).toHaveClass('nowo-search__toggle demo-search-toggle');
    await expect(search).toHaveAttribute('data-nowo-ui-bundle', 'NowoSearchKitBundle');
    await page.getByTestId('nowo-search-toggle').click();
    await expect(search).toHaveClass(/is-open/);
    await expect(field).toBeFocused();
    const popover = page.getByTestId('nowo-search-popover');
    await expect(popover.getByRole('option').first()).toBeVisible();

    await field.fill('billing');
    await expect(popover.locator('[data-group="projects"]')).toBeVisible();
    await expect(popover.getByRole('option').first().locator('mark')).toHaveText('Billing');
    await field.press('Enter');
    await expect(page).toHaveURL(/\/en\/projects\/billing$/);
  });

  test('permissions: bob never sees private projects nor the Users group', async ({ page }) => {
    await signIn(page, 'bob');
    await page.keyboard.press('Control+k');
    const field = page.getByTestId('nowo-search').getByRole('combobox');
    await field.fill('billing');
    await expect(page.getByTestId('nowo-search-popover')).toContainText('No results');
    await field.fill('alice');
    await expect(page.getByTestId('nowo-search-popover')).toContainText('No results');
  });

  test('opens with "/" and folds with Escape; the scope chip goes with Backspace', async ({ page }) => {
    await signIn(page, 'alice');
    const search = page.getByTestId('nowo-search');
    await page.locator('main').click({ position: { x: 5, y: 5 } });
    await page.keyboard.press('/');
    await expect(search).toHaveClass(/is-open/);
    await page.keyboard.press('Escape');
    await expect(search).not.toHaveClass(/is-open/);

    await page.goto('/en/projects/shop');
    await page.getByTestId('nowo-search-toggle').click();
    const scope = page.getByTestId('nowo-search-scope');
    await expect(scope).toContainText('Online shop');
    await search.getByRole('combobox').press('Backspace');
    await expect(scope).toBeHidden();
  });

  test('phones get the field across the header above a dimmed backdrop that closes it', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, 'alice');
    await page.getByTestId('nowo-search-toggle').click();
    const search = page.getByTestId('nowo-search');
    await expect(search).toHaveClass(/is-open/);
    const box = await page.locator('.nowo-search__box').boundingBox();
    expect(box?.width ?? 0).toBeGreaterThan(360);
    const backdrop = await search.evaluate((el) => {
      const style = getComputedStyle(el, '::before');
      return { position: style.position, background: style.backgroundColor };
    });
    expect(backdrop.position).toBe('fixed');
    expect(backdrop.background).not.toBe('rgba(0, 0, 0, 0)');
    await page.mouse.click(195, 800);
    await expect(search).not.toHaveClass(/is-open/);
  });
});
