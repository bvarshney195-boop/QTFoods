import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const AVAILABLE = '00000000-0000-4000-8000-000000001213';
const BLOCKED = '00000000-0000-4000-8000-000000001214';
const RETURN_TARGET = '00000000-0000-4000-8000-000000001216';
const TRANSFER_TARGET = '00000000-0000-4000-8000-000000001217';
const EXPIRED_SOURCE = '00000000-0000-4000-8000-000000001218';
const EXPIRED_TARGET = '00000000-0000-4000-8000-000000001219';

test('Operations Manager posts the complete controlled inventory operation cycle', async ({ page }) => {
  await loginAndSelect(page);
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="INV-ISS"]').click();
  await expect(page.getByRole('heading', { name: 'Stock issues & returns' })).toBeVisible();
  await createAndPost(page, {
    number: 'E2E-ISS-001', reason: 'PRODUCTION_ISSUE', source: AVAILABLE, quantity: '4',
  });
  await createAndPost(page, {
    number: 'E2E-RET-001', type: 'RETURN', reason: 'PRODUCTION_RETURN', target: RETURN_TARGET, quantity: '2',
  });

  await navigation.locator('[data-screen-code="INV-TRF"]').click();
  await expect(page.getByRole('heading', { name: 'Stock transfers' })).toBeVisible();
  await createAndPost(page, {
    number: 'E2E-TRF-001', reason: 'LINE_REPLENISHMENT', source: AVAILABLE,
    target: TRANSFER_TARGET, quantity: '5',
  });

  await navigation.locator('[data-screen-code="INV-COUNT"]').click();
  await expect(page.getByRole('heading', { name: 'Stock counts' })).toBeVisible();
  await createAndPost(page, {
    number: 'E2E-CNT-001', reason: 'CYCLE_COUNT', source: BLOCKED, counted: '14',
  });
  await createAndPost(page, {
    number: 'E2E-ADJ-001', type: 'ADJUSTMENT', reason: 'SCALE_CORRECTION', source: BLOCKED,
    quantity: '2', direction: 'INCREASE',
  });

  await navigation.locator('[data-screen-code="INV-EXP"]').click();
  await expect(page.getByRole('heading', { name: 'Expiry & disposal' })).toBeVisible();
  await createAndPost(page, {
    number: 'E2E-EXP-001', reason: 'SHELF_LIFE', source: EXPIRED_SOURCE,
    target: EXPIRED_TARGET, quantity: '8',
  });
  await createAndPost(page, {
    number: 'E2E-DSP-001', type: 'DISPOSAL', reason: 'APPROVED_DESTRUCTION',
    source: EXPIRED_TARGET, quantity: '3',
  });

  await navigation.locator('[data-screen-code="INV-STK"]').click();
  await expect(page.getByRole('heading', { name: 'Stock overview' })).toBeVisible();
  await page.getByRole('button', { name: 'Movement history' }).click();
  await expect(page.getByRole('heading', { name: 'Immutable movement history' })).toBeVisible();
  const ledger = page.locator('.movement-ledger-table');
  for (const number of [
    'E2E-ISS-001', 'E2E-RET-001', 'E2E-TRF-001', 'E2E-CNT-001',
    'E2E-ADJ-001', 'E2E-EXP-001', 'E2E-DSP-001',
  ]) {
    await expect(ledger.getByText(number)).toBeVisible();
  }
  await expect(page.locator('.inventory-kpis')).toContainText('7');

  const disposalRow = ledger.locator('tbody tr').filter({ hasText: 'E2E-DSP-001' });
  await disposalRow.getByRole('button', { name: 'Open' }).click();
  const detail = page.locator('.inventory-editor');
  await expect(detail).toContainText('Inventory disposal');
  await expect(detail).toContainText('3 KG');
  await expect(detail).toContainText('Operations Manager');
});

async function createAndPost(page: Page, command: {
  number: string;
  type?: 'RETURN' | 'ADJUSTMENT' | 'DISPOSAL';
  reason: string;
  source?: string;
  target?: string;
  quantity?: string;
  counted?: string;
  direction?: 'INCREASE' | 'DECREASE';
}) {
  await page.getByRole('button', { name: '+ New' }).click();
  const editor = page.locator('.inventory-operation-editor');
  if (command.type) await editor.getByLabel('Operation type').selectOption(command.type);
  await editor.getByLabel('Operation number').fill(command.number);
  await editor.getByLabel('Operation reason code').fill(command.reason);
  if (command.source) await editor.getByLabel('Line 1 source position').selectOption(command.source);
  if (command.target) await editor.getByLabel('Line 1 target position').selectOption(command.target);
  if (command.quantity) await editor.getByLabel('Line 1 quantity').fill(command.quantity);
  if (command.counted) await editor.getByLabel('Line 1 counted quantity').fill(command.counted);
  if (command.direction) await editor.getByLabel('Line 1 adjustment direction').selectOption(command.direction);
  await editor.getByRole('button', { name: 'Create draft' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft operation created');
  await editor.getByRole('button', { name: 'Post operation' }).click();
  await expect(editor.getByRole('status')).toContainText('Operation posted with 1 ledger movement');
  await expect(editor.locator('.status').filter({ hasText: /^Posted$/ })).toBeVisible();
}

async function loginAndSelect(page: Page): Promise<void> {
  await signInAndSelect(page, 'operations.user@qtfoods.local');
}
