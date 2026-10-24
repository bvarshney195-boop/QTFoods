import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const DEMAND_NUMBER = 'E2E-OPT-DEMAND-001';
const PLAN_NUMBER = 'E2E-OPT-PLAN-001';

test('optimisation versions demand, exposes limitations, requires review, and closes on measured outcomes', async ({ page }) => {
  test.setTimeout(180_000);
  await loginAndSelect(page, 'operations.user@qtfoods.local');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  const editor = page.locator('.requisition-editor');

  await navigation.locator('[data-screen-code="PLAN-DEM"]').click();
  await expect(page.getByRole('heading', { name: 'Demand planning' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Plan number').fill(DEMAND_NUMBER);
  await editor.getByLabel('Plan name').fill('E2E governed optimisation source');
  await editor.getByLabel('Horizon start').fill('2026-09-13');
  await editor.getByLabel('Horizon end').fill('2026-09-27');
  await selectByText(editor.getByLabel('Demand line 1 output SKU'), 'SKU-APPLE-100');
  await editor.getByLabel('Demand date').fill('2026-09-20');
  await editor.getByLabel('Demand line 1 quantity').fill('600');
  await editor.getByRole('button', { name: 'Create demand plan' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft demand plan created');
  await editor.getByRole('button', { name: 'Release to MRP' }).click();
  await expect(editor.getByRole('status')).toContainText('Demand released to MRP');

  await navigation.locator('[data-screen-code="OPT-PLAN"]').click();
  await expect(page.getByRole('heading', { name: 'Planning recommendations' })).toBeVisible();
  await expect(page.locator('.optimisation-notice')).toContainText('checksum-versioned');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Plan number').fill(PLAN_NUMBER);
  await editor.getByLabel('Name').fill('E2E balanced service scenario');
  await selectByText(editor.getByLabel('Released demand plan'), DEMAND_NUMBER);
  await editor.getByLabel('Service target %').fill('98');
  await editor.getByLabel('Safety stock %').fill('10');
  await editor.getByLabel('Planning lead days').fill('5');
  await editor.getByLabel('Maximum utilisation %').fill('85');
  await editor.getByLabel('Annual holding rate %').fill('18');
  await editor.getByLabel('Shortage penalty / unit').fill('12');
  await editor.getByLabel('Assumptions').fill('Stable demand with capacity released only through the manufacturing schedule.');
  await editor.getByRole('button', { name: 'Capture input version 1' }).click();
  await expect(editor.getByRole('status')).toContainText('Immutable input version 1 captured');
  await expect(editor.getByText(/^[a-f0-9]{64}$/)).toBeVisible();

  await editor.getByRole('button', { name: 'Generate recommendations' }).click();
  await expect(editor.getByRole('status')).toContainText('Recommendations generated');
  await expect(editor.getByText('Current recommendations and limitations')).toBeVisible();
  await expect(editor.getByText(/deterministic net-requirements guidance/i)).toBeVisible();
  await expect(editor.getByText(/not a capacity booking/i)).toBeVisible();
  await editor.getByRole('button', { name: 'Submit for review' }).click();
  await expect(editor.getByRole('status')).toContainText('independent human decision');
  await expect(editor.locator('.status').filter({ hasText: /^Submitted$/ }).first()).toBeVisible();
  await logout(page);

  await loginAndSelect(page, 'finance.user@qtfoods.local');
  await openOptimisation(page, PLAN_NUMBER);
  await editor.getByRole('button', { name: 'Approve recommendations' }).click();
  await editor.getByLabel('Decision evidence').fill('Reviewed the source version, checksum, cost scope, capacity warning, and service assumption.');
  await editor.getByRole('button', { name: 'Approve independently' }).click();
  await expect(editor.getByRole('status')).toContainText('independently approved');
  await expect(editor.locator('.status').filter({ hasText: /^Approved$/ }).first()).toBeVisible();
  await logout(page);

  await loginAndSelect(page, 'operations.user@qtfoods.local');
  await openOptimisation(page, PLAN_NUMBER);
  await editor.getByRole('button', { name: 'Record execution outcome' }).click();
  await editor.getByLabel('Outcome evidence').fill('Observed after the controlled planning horizon closed and reconciled to production records.');
  await editor.getByRole('button', { name: 'Record outcome' }).click();
  await expect(editor.getByRole('status')).toContainText('Execution outcome recorded');
  await expect(editor.getByText(/outcome v1/i)).toBeVisible();
  await editor.getByRole('button', { name: 'Complete after outcomes' }).click();
  await expect(editor.getByRole('status')).toContainText('completed after every recommendation received an outcome');
  await expect(editor.locator('.status').filter({ hasText: /^Completed$/ }).first()).toBeVisible();
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
});

async function openOptimisation(page: Page, planNumber: string): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="OPT-PLAN"]').click();
  await expect(page.getByRole('heading', { name: 'Planning recommendations' })).toBeVisible();
  await page.getByLabel('Optimisation search').fill(planNumber);
  const row = page.locator('.optimisation-register tbody tr').filter({ hasText: planNumber });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('.optimisation-editor')).toContainText(planNumber);
}

async function selectByText(select: ReturnType<Page['locator']>, text: string): Promise<void> {
  const option = select.locator('option').filter({ hasText: text });
  await select.selectOption(await option.getAttribute('value') ?? '');
}

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await signInAndSelect(page, email);
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}
