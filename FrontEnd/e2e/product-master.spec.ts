import { expect, test, type Locator, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('Operations Manager builds a governed product master from brand through quality specification', async ({ page }) => {
  await loginAndSelect(page);
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="MD-BRAND"]').click();
  await expect(page.getByRole('heading', { name: 'Brands & agreements' })).toBeVisible();
  let editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Brand code').fill('E2E-BRAND');
  await editor.getByLabel('Brand name').fill('E2E Product Brand');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByRole('button', { name: 'Add agreement' }).click();
  await editor.getByLabel('Agreement number 1').fill('E2E-AGR-001');
  await editor.getByLabel('Agreement effective from 1').fill('2026-04-01');
  await editor.getByLabel('Agreement status 1').selectOption('ACTIVE');
  await editor.getByRole('button', { name: 'Create brand' }).click();
  await expectSuccess(editor, 'E2E-BRAND was created as ACTIVE');

  await navigation.locator('[data-screen-code="MD-ITEM"]').click();
  await expect(page.getByRole('heading', { name: 'Items & units' })).toBeVisible();
  editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Item code').fill('E2E-ITEM');
  await editor.getByLabel('Item name').fill('E2E Ingredient');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('Item type').selectOption('RAW_MATERIAL');
  await editor.getByLabel('Base UOM').selectOption('KG');
  await editor.getByRole('button', { name: 'Add conversion' }).click();
  await editor.getByLabel('Conversion from UOM 1').selectOption('KG');
  await editor.getByLabel('Conversion to UOM 1').selectOption('G');
  await editor.getByLabel('Conversion multiplier 1').fill('1000');
  await editor.getByRole('button', { name: 'Create item' }).click();
  await expectSuccess(editor, 'E2E-ITEM was created as ACTIVE');

  await navigation.locator('[data-screen-code="MD-SKU"]').click();
  await expect(page.getByRole('heading', { name: 'Products & packs' })).toBeVisible();
  editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('SKU code').fill('E2E-SKU');
  await editor.getByLabel('SKU name').fill('E2E Ingredient SKU');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('SKU catalog item').selectOption({ label: 'E2E-ITEM · E2E Ingredient' });
  await editor.getByLabel('SKU barcode').fill('8901999900001');
  await editor.getByLabel('Pack code 1').fill('E2E-PACK');
  await editor.getByLabel('Pack name 1').fill('E2E kilogram pack');
  await editor.getByLabel('Pack UOM 1').selectOption('KG');
  await editor.getByRole('button', { name: 'Create SKU' }).click();
  await expectSuccess(editor, 'E2E-SKU was created as ACTIVE');

  await navigation.locator('[data-screen-code="MD-REC"]').click();
  await expect(page.getByRole('heading', { name: 'Recipes & materials' })).toBeVisible();
  editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Recipe code').fill('E2E-RECIPE');
  await editor.getByLabel('Recipe name').fill('E2E Ingredient Recipe');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('Recipe output SKU').selectOption({ label: 'E2E-SKU · E2E Ingredient SKU' });
  await editor.getByLabel('Recipe output UOM').selectOption('KG');
  await editor.getByRole('button', { name: 'Add component' }).click();
  await editor.getByLabel('Component SKU 1').selectOption({ label: 'SKU-APPLE-BASE · Apple Ingredient Base' });
  await editor.getByLabel('Component quantity 1').fill('0.25');
  await editor.getByLabel('Component UOM 1').selectOption('KG');
  await editor.getByRole('button', { name: 'Create recipe' }).click();
  await expectSuccess(editor, 'E2E-RECIPE was created as ACTIVE');

  await navigation.locator('[data-screen-code="MD-ROUTE"]').click();
  await expect(page.getByRole('heading', { name: 'Production steps' })).toBeVisible();
  editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Route code').fill('E2E-ROUTE');
  await editor.getByLabel('Route name').fill('E2E Ingredient Route');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('Route catalog item').selectOption({ label: 'E2E-ITEM · E2E Ingredient' });
  await editor.getByRole('button', { name: 'Add operation' }).click();
  await editor.getByLabel('Operation name 1').fill('Blend');
  await editor.getByLabel('Operation work centre 1').fill('BLEND-01');
  await editor.getByLabel('Operation setup minutes 1').fill('15');
  await editor.getByLabel('Operation run minutes 1').fill('0.05');
  await editor.getByRole('button', { name: 'Create route' }).click();
  await expectSuccess(editor, 'E2E-ROUTE was created as ACTIVE');

  await navigation.locator('[data-screen-code="MD-SPEC"]').click();
  await expect(page.getByRole('heading', { name: 'Quality standards' })).toBeVisible();
  editor = page.locator('.product-master-editor');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Specification code').fill('E2E-SPEC');
  await editor.getByLabel('Specification name').fill('E2E Ingredient Standard');
  await editor.getByLabel('Initial status').selectOption('ACTIVE');
  await editor.getByLabel('Specification target type').selectOption('ITEM');
  await editor.getByLabel('Specification catalog item').selectOption({ label: 'E2E-ITEM · E2E Ingredient' });
  await editor.getByRole('button', { name: 'Add parameter' }).click();
  await editor.getByLabel('Parameter code 1').fill('MOISTURE');
  await editor.getByLabel('Parameter name 1').fill('Moisture');
  await editor.getByLabel('Parameter UOM 1').selectOption('G');
  await editor.getByLabel('Parameter minimum 1').fill('0');
  await editor.getByLabel('Parameter target 1').fill('2');
  await editor.getByLabel('Parameter maximum 1').fill('5');
  await editor.getByRole('button', { name: 'Create specification' }).click();
  await expectSuccess(editor, 'E2E-SPEC was created as ACTIVE');

  await expect(page.locator('.product-master-table tbody tr').filter({ hasText: 'E2E-SPEC' })).toContainText('E2E Ingredient Standard');
});

async function expectSuccess(editor: Locator, message: string): Promise<void> {
  await expect(editor.getByRole('status')).toContainText(message);
}

async function loginAndSelect(page: Page): Promise<void> {
  await signInAndSelect(page, 'operations.user@qtfoods.local');
}
