import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('Operations Manager governs stock ownership, lots, and reservations', async ({ page }) => {
  await loginAndSelect(page);
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="INV-STK"]').click();
  await expect(page.getByRole('heading', { name: 'Stock, Lots & Ownership' })).toBeVisible();

  const stockTable = page.locator('.inventory-table');
  const availableRow = stockTable.locator('tbody tr').filter({ hasText: 'RM-APPLE-2609A' })
    .filter({ hasText: 'Q & T FOODS owned stock' }).filter({ hasText: '125' });
  await expect(availableRow).toContainText('105');
  await expect(availableRow).toContainText('20');
  await availableRow.getByRole('button', { name: 'Open' }).click();
  const editor = page.locator('.inventory-editor');
  await expect(editor.getByText('RSV-MRP-2609-001')).toBeVisible();

  await editor.getByLabel('Reservation number').fill('RSV-E2E-001');
  await editor.getByLabel('Reservation quantity').fill('5');
  await editor.getByLabel('Reservation purpose').fill('E2E production material reservation');
  await editor.getByRole('button', { name: 'Reserve stock' }).click();
  await expect(editor.getByRole('status')).toContainText('Stock reservation created');
  const reservation = editor.locator('.reservation-card').filter({ hasText: 'RSV-E2E-001' });
  await expect(reservation).toContainText('5');

  await editor.getByLabel('Reservation release reason').fill('E2E production schedule cancelled');
  await reservation.getByRole('button', { name: 'Release reservation' }).click();
  await expect(editor.getByRole('status')).toContainText('RSV-E2E-001 was released');
  await expect(editor.locator('.reservation-card').filter({ hasText: 'RSV-E2E-001' })).toContainText('Released');

  await page.getByRole('button', { name: 'Owners', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Inventory owner register' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Owner code').fill('E2E-CENTRAL');
  await editor.getByLabel('Owner name').fill('E2E Central consignment stock');
  await editor.getByLabel('Owner party').selectOption({ label: 'DIST-CENTRAL · Central Retail Distribution' });
  await editor.getByLabel('Owner initial status').selectOption('ACTIVE');
  await editor.getByRole('button', { name: 'Create owner' }).click();
  await expect(editor.getByRole('status')).toContainText('Inventory owner created');
  await expect(page.locator('.inventory-table tbody tr').filter({ hasText: 'E2E-CENTRAL' })).toContainText('E2E Central consignment stock');
  await editor.getByLabel('Target owner status').selectOption('INACTIVE');
  await editor.getByLabel('Owner status reason').fill('E2E consignment relationship ended');
  await editor.getByRole('button', { name: 'Apply status' }).click();
  await expect(editor.getByRole('status')).toContainText('Owner moved to Inactive');

  await page.getByRole('button', { name: 'Lots', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Lot traceability register' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Internal lot code').fill('E2E-RM-LOT-001');
  await editor.getByLabel('Lot SKU').selectOption({ label: 'SKU-APPLE-BASE · Apple Ingredient Base' });
  await editor.getByLabel('Lot origin').selectOption('PURCHASE');
  await editor.getByLabel('Lot supplier').selectOption({ label: 'DIST-CENTRAL · Central Retail Distribution' });
  await editor.getByLabel('Supplier lot code').fill('SUP-E2E-001');
  await editor.getByLabel('Manufacture date').fill('2026-09-10');
  await editor.getByLabel('Expiry date').fill('2099-12-31');
  await editor.getByLabel('Lot notes').fill('E2E supplier traceability fixture');
  await editor.getByLabel('Lot initial status').selectOption('ACTIVE');
  await editor.getByRole('button', { name: 'Create lot' }).click();
  await expect(editor.getByRole('status')).toContainText('Inventory lot created');
  await expect(page.locator('.inventory-table tbody tr').filter({ hasText: 'E2E-RM-LOT-001' })).toContainText('SUP-E2E-001');
});

async function loginAndSelect(page: Page): Promise<void> {
  await signInAndSelect(page, 'operations.user@qtfoods.local');
}
