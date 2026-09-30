import { expect, test, type Page } from '@playwright/test';

test('ERP Administrator traces a scoped change through audit evidence and outbox delivery', async ({ page }) => {
  await loginAndSelect(page);
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="ADM-LOC"]').click();
  await expect(page.getByRole('heading', { name: 'Plant Locations' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  const editor = page.locator('.admin-editor');
  await editor.getByLabel('Location code').fill('CTRL-AUDIT');
  await editor.getByLabel('Name').fill('Control Audit Probe');
  await editor.getByLabel('Location type').selectOption('WAREHOUSE');
  await editor.getByRole('button', { name: 'Create location' }).click();
  await expect(page.getByRole('status')).toContainText('CTRL-AUDIT was created');

  await navigation.locator('[data-screen-code="ADM-AUD"]').click();
  await expect(page.getByRole('heading', { name: 'Audit & Evidence' })).toBeVisible();
  await page.getByLabel('Search audit').fill('CREATE_LOCATION');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  const auditRow = page.locator('.control-table tbody tr').filter({ hasText: 'CREATE_LOCATION' }).first();
  await expect(auditRow).toContainText('Demo ERP Administrator');
  await auditRow.getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('.control-detail .json-view')).toContainText('CTRL-AUDIT');

  await navigation.locator('[data-screen-code="ADM-INT"]').click();
  await expect(page.getByRole('heading', { name: 'Integration Operations' })).toBeVisible();
  await page.getByLabel('Search outbox').fill('foundation.location.created');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  const outboxRow = page.locator('.control-table tbody tr').filter({ hasText: 'foundation.location.created' }).first();
  await expect(outboxRow).toBeVisible();
  await page.getByRole('button', { name: 'Process due now' }).click();
  await expect(page.getByRole('status')).toContainText('Processing complete');
  await expect(outboxRow).toContainText('Delivered');
  await outboxRow.getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('.control-detail')).toContainText('log:');
  await expect(page.locator('.control-detail')).toContainText('Attempt 1');
});

async function loginAndSelect(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.getByLabel('Email').fill('admin.user@qtfoods.local');
  await page.getByLabel('Password').fill('prototype');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('button', { name: /Training Plant/ }).click();
}
