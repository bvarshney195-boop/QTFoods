import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('Operations Manager creates, updates, and places a complete customer party on hold', async ({ page }) => {
  await loginAndSelect(page);
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="MD-PARTY"]').click();
  await expect(page.getByRole('heading', { name: 'Customers & suppliers' })).toBeVisible();
  await expect(page.getByText('Controlled party aggregate')).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();

  const editor = page.locator('.party-editor');
  await editor.getByLabel('Party code').fill('E2E-CUST');
  await editor.getByLabel('Display name').fill('E2E Customer');
  await editor.getByLabel('Legal name').fill('E2E Customer Private Limited');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('Address line 1 1').fill('18 Market Road');
  await editor.getByLabel('Address city 1').fill('Ahmedabad');
  await editor.getByLabel('Address region 1').fill('Gujarat');
  await editor.getByLabel('Address postal code 1').fill('380001');
  await editor.getByLabel('Contact name 1').fill('Nisha Patel');
  await editor.getByLabel('Contact email 1').fill('nisha.patel@example.com');
  await editor.getByRole('button', { name: 'Add registration' }).click();
  await editor.getByLabel('Tax number 1').fill('24AAACE1234A1Z9');
  await editor.getByLabel('Payment terms days').fill('21');
  await editor.getByLabel('Credit limit').fill('75000');
  await editor.getByLabel('Incoterm').fill('DAP');
  await editor.getByRole('button', { name: 'Create party' }).click();
  await expect(editor.getByRole('status')).toContainText('E2E-CUST was created as ACTIVE');

  const row = page.locator('.party-table tbody tr').filter({ hasText: 'E2E-CUST' });
  await expect(row).toContainText('E2E Customer');
  await expect(row).toContainText('Nisha Patel');
  await editor.getByLabel('Display name').fill('E2E Customer Updated');
  await editor.getByRole('button', { name: 'Save party' }).click();
  await expect(editor.getByRole('status')).toContainText('was saved with record version 2');
  await expect(row).toContainText('E2E Customer Updated');

  await editor.getByLabel('Target party status').selectOption('ON_HOLD');
  await editor.getByLabel('Party status reason').fill('E2E credit review');
  await editor.getByRole('button', { name: 'Apply status' }).click();
  await expect(editor.getByRole('status')).toContainText('moved to On hold at version 3');
  await expect(row).toContainText('On hold');
});

async function loginAndSelect(page: Page): Promise<void> {
  await signInAndSelect(page, 'operations.user@qtfoods.local');
}
