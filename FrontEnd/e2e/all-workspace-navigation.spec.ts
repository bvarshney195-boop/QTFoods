import { expect, test } from '@playwright/test';
import { screenRegistry } from '../src/data/screenRegistry';
import { signInAndSelect } from './support/auth';

/**
 * Navigation smoke only, run against the disposable PostgreSQL/Redis E2E stack.
 * This checks every business screen link and lazy-loaded page; functional CRUD,
 * accounting, approval, and RBAC tests are exercised by separate suites.
 */
test('ERP Administrator opens every registered business workspace via its navigation link', async ({ page }) => {
  test.setTimeout(600_000);
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await signInAndSelect(page, 'admin.user@qtfoods.local');

  const screens = screenRegistry.filter(({ code }) => !code.startsWith('ACC-'));
  expect(screens).toHaveLength(69);

  const menu = page.getByRole('navigation', { name: 'Main menu' });
  await expect(menu.locator('[data-screen-code]')).toHaveCount(screens.length);

  for (const { code, title } of screens) {
    const link = menu.locator(`[data-screen-code="${code}"]`);
    await expect(link, `Missing menu entry for ${code} (${title})`).toBeVisible();
    await link.click();
    await expect(page, `Wrong route after clicking ${code}`).toHaveURL(new RegExp(`#${code}$`));
    await expect(link).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('#erp-main-content .page-loading')).toHaveCount(0);
    await expect(page.locator('#erp-main-content')).not.toBeEmpty();
    expect(errors, `Browser runtime errors opening ${code} (${title})`).toEqual([]);
  }
});
