import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('manufacturing planning releases demand, runs MRP, schedules capacity, and reserves material', async ({ page }) => {
  test.setTimeout(150_000);
  await loginAndSelect(page, 'operations.user@qtfoods.local');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  const editor = page.locator('.requisition-editor');

  await navigation.locator('[data-screen-code="PLAN-DEM"]').click();
  await expect(page.getByRole('heading', { name: 'Demand Planning' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Plan number').fill('E2E-PLAN-DEMAND-001');
  await editor.getByLabel('Plan name').fill('E2E apple snack weekly demand');
  await editor.getByLabel('Horizon start').fill('2026-09-13');
  await editor.getByLabel('Horizon end').fill('2026-09-27');
  await selectByText(editor.getByLabel('Demand line 1 output SKU'), 'SKU-APPLE-100');
  await editor.getByLabel('Demand date').fill('2026-09-20');
  await editor.getByLabel('Demand line 1 quantity').fill('100');
  await editor.getByRole('button', { name: 'Create demand plan' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft demand plan created');
  await editor.getByRole('button', { name: 'Release to MRP' }).click();
  await expect(editor.getByRole('status')).toContainText('Demand released to MRP');
  await expect(editor.locator('.status').filter({ hasText: /^Released$/ })).toBeVisible();

  await navigation.locator('[data-screen-code="PLAN-MRP"]').click();
  await expect(page.getByRole('heading', { name: 'Material Requirements Planning' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('MRP run number').fill('E2E-PLAN-MRP-001');
  await selectByText(editor.getByLabel('Released demand plan'), 'E2E-PLAN-DEMAND-001');
  await editor.getByLabel('Planning date').fill('2026-09-13');
  await editor.getByRole('button', { name: 'Run MRP' }).click();
  await expect(editor.getByRole('status')).toContainText('MRP completed');
  await expect(editor).toContainText('10.304569 KG');
  await expect(editor).toContainText('SKU-APPLE-BASE');

  await navigation.locator('[data-screen-code="PLAN-SCH"]').click();
  await expect(page.getByRole('heading', { name: 'Production Schedule' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Completed MRP run'), 'E2E-PLAN-MRP-001');
  await editor.getByLabel('Schedule number').fill('E2E-PLAN-SCH-001');
  await editor.getByLabel('Horizon start').fill('2026-09-14');
  await editor.getByLabel('Horizon end').fill('2026-09-20');
  await editor.getByRole('button', { name: 'Create schedule' }).click();
  await expect(editor.getByRole('status')).toContainText('Capacity schedule created');
  await expect(editor).toContainText('MIX-01');
  await expect(editor).toContainText('OVEN-01');
  await expect(editor).toContainText('PACK-01');
  await editor.getByRole('button', { name: 'Release and reserve' }).click();
  await expect(editor.getByRole('status')).toContainText('reserved by FEFO');
  await expect(editor).toContainText('RM-APPLE-2609A');
  await expect(editor).toContainText('10.304569 KG');

  page.once('dialog', (dialog) => dialog.accept('E2E production window withdrawn after planning verification.'));
  await editor.getByRole('button', { name: 'Cancel' }).click();
  await expect(editor.getByRole('status')).toContainText('linked reservations released');
  await expect(editor.locator('.status').filter({ hasText: /^Cancelled$/ })).toBeVisible();
  await expect(editor).toContainText('Released');

  await navigation.locator('[data-screen-code="PLAN-MRP"]').click();
  await page.getByLabel('Search', { exact: true }).fill('E2E-PLAN-MRP-001');
  await openRow(page, 'E2E-PLAN-MRP-001');
  page.once('dialog', (dialog) => dialog.accept('E2E schedule was cancelled after verification.'));
  await editor.getByRole('button', { name: 'Cancel MRP run' }).click();
  await expect(editor.getByRole('status')).toContainText('MRP run cancelled');

  await navigation.locator('[data-screen-code="PLAN-DEM"]').click();
  await page.getByLabel('Search', { exact: true }).fill('E2E-PLAN-DEMAND-001');
  await openRow(page, 'E2E-PLAN-DEMAND-001');
  page.once('dialog', (dialog) => dialog.accept('E2E demand horizon closed after verification.'));
  await editor.getByRole('button', { name: 'Cancel' }).click();
  await expect(editor.getByRole('status')).toContainText('Demand plan cancelled');
});

async function openRow(page: Page, text: string): Promise<void> {
  const row = page.locator('.requisition-table tbody tr').filter({ hasText: text });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Open' }).click();
}

async function selectByText(select: ReturnType<Page['locator']>, text: string): Promise<void> {
  const option = select.locator('option').filter({ hasText: text });
  await select.selectOption(await option.getAttribute('value') ?? '');
}

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await signInAndSelect(page, email);
}
