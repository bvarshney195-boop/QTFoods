import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const ROUTE = '00000000-0000-4000-8000-000000003011';
const SOURCE_POSITION = '00000000-0000-4000-8000-000000002602';
const DESTINATION_POSITION = '00000000-0000-4000-8000-000000003021';
const TRANSFER_NUMBER = 'E2E-SCALE-XFER-001';

test('multi-plant scale separates source dispatch from destination receipt', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  const transferNumber = testInfo.retry === 0
    ? TRANSFER_NUMBER
    : `${TRANSFER_NUMBER}-R${testInfo.retry}-${Date.now().toString(36).toUpperCase()}`;

  await loginAndSelect(page, 'operations.user@qtfoods.local', 'Training Plant');
  await openScale(page);
  await page.getByRole('button', { name: '+ New transfer', exact: true }).click();
  await submitForm(page, 'New transfer', {
    transfer_number: transferNumber,
    plant_transfer_route_id: ROUTE,
    transfer_date: '2026-09-15',
    expected_arrival_date: '2026-09-16',
    commercial_reference: null,
    notes: 'Governed browser transfer between QTF plants.',
    lines: [{
      source_position_id: SOURCE_POSITION,
      destination_position_id: DESTINATION_POSITION,
      quantity_base: '3',
      notes: 'Three released packs.',
    }],
  });
  await expect(page.locator('.feedback-toast')).toContainText('New transfer saved successfully. Current status: Draft.');
  await searchAndOpen(page, transferNumber);
  await page.getByRole('button', { name: 'Submit', exact: true }).click();
  await expect(page.locator('.feedback-toast')).toContainText('Transfer submitted for independent source approval');
  await logout(page);

  await loginAndSelect(page, 'admin.user@qtfoods.local', 'Training Plant');
  await openScale(page);
  await searchAndOpen(page, transferNumber);
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.locator('.feedback-toast')).toContainText('Transfer independently approved at source');
  await page.getByRole('button', { name: 'Dispatch', exact: true }).click();
  await expect(page.locator('.feedback-toast')).toContainText('Source stock dispatched into governed transit');

  const source = await transferDetail(page, transferNumber);
  expect(source.status).toBe('IN_TRANSIT');
  expect(source.lines[0]?.outbound_movement_id).toBeTruthy();
  expect(source.lines[0]?.inbound_movement_id).toBeNull();

  await closeP2Drawer(page);
  await page.locator('.context-button').click();
  await page.getByRole('button', { name: /Finance Review/ }).click();
  await openScale(page);
  await searchAndOpen(page, transferNumber);
  await expect(page.getByText('DESTINATION', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Receive', exact: true }).click();
  await expect(page.locator('.feedback-toast')).toContainText('Destination stock received with linked inbound movement evidence');

  const received = await transferDetail(page, transferNumber);
  expect(received.status).toBe('RECEIVED');
  expect(received.lines[0]?.outbound_movement_id).toBeTruthy();
  expect(received.lines[0]?.inbound_movement_id).toBeTruthy();
  expect(received.lines[0]?.received_destination_quantity).toBe('3.000000');
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
});

type TransferDetail = {
  data: {
    status: string;
    lines: Array<{
      outbound_movement_id: string | null;
      inbound_movement_id: string | null;
      received_destination_quantity: string;
    }>;
  };
};

async function openScale(page: Page): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="SCALE-PLANT"]').click();
  await expect(page.getByRole('heading', { name: 'Multi-plant operations' })).toBeVisible();
  await expect(page.locator('.business-guidance')).toBeVisible();
}

async function submitForm(page: Page, label: string, body: unknown): Promise<void> {
  await expect(page.getByRole('heading', { name: label })).toBeVisible();
  await fillFormValue(page, body, '');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
}

async function fillFormValue(page: Page, value: unknown, path: string): Promise<void> {
  if (Array.isArray(value)) {
    const section = page.locator(`section[data-field-path="${path}"]`);
    if (await section.count()) {
      while (await section.locator('.p2-entry-line-card').count() < value.length) await section.getByRole('button', { name: /^\+ Add / }).click();
      while (await section.locator('.p2-entry-line-card').count() > value.length) await section.locator('.p2-entry-line-card').last().getByRole('button', { name: 'Remove' }).click();
    }
    for (let index = 0; index < value.length; index += 1) await fillFormValue(page, value[index], `${path}.${index}`);
    return;
  }
  if (value !== null && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) await fillFormValue(page, item, path ? `${path}.${key}` : key);
    return;
  }
  const control = page.locator(`[data-field-path="${path}"]`);
  if (!await control.count()) {
    if (value === null) return;
    throw new Error(`No form field was rendered for ${path}.`);
  }
  const details = await control.first().evaluate((element) => ({
    tag: element.tagName.toLowerCase(),
    type: element instanceof HTMLInputElement ? element.type : '',
    readOnly: element instanceof HTMLInputElement ? element.readOnly : false,
  }));
  if (details.readOnly) return;
  if (details.tag === 'select') { await control.first().selectOption(value === null ? '' : String(value)); return; }
  if (details.type === 'checkbox') {
    if (Boolean(value)) await control.first().check(); else await control.first().uncheck();
    return;
  }
  const text = value === null ? '' : String(value);
  await control.first().fill(details.type === 'datetime-local' ? text.replace(/Z$/, '').slice(0, 16) : text);
}

async function searchAndOpen(page: Page, text: string): Promise<void> {
  await page.getByLabel('Multi-Plant Control search').fill(text);
  const row = page.locator('.requisition-table tbody tr').filter({ hasText: text });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Open' }).click();
}

async function transferDetail(page: Page, transferNumber: string): Promise<TransferDetail['data']> {
  const response = await page.evaluate(async (number) => {
    const list = await fetch(`/api/v1/scale/plants?q=${encodeURIComponent(number)}`, {
      credentials: 'include', headers: { Accept: 'application/json' },
    });
    const workspace = await list.json() as { data: Array<{ id: string }> };
    const id = workspace.data[0]?.id;
    if (!list.ok || !id) return { ok: false, status: list.status, body: workspace };
    const detail = await fetch(`/api/v1/scale/transfers/${id}`, {
      credentials: 'include', headers: { Accept: 'application/json' },
    });
    return { ok: detail.ok, status: detail.status, body: await detail.json() };
  }, transferNumber);
  if (!response.ok) throw new Error(`Transfer detail failed (${response.status}): ${JSON.stringify(response.body)}`);
  return (response.body as TransferDetail).data;
}

async function loginAndSelect(page: Page, email: string, plant: string): Promise<void> {
  await signInAndSelect(page, email, 'prototype', plant);
}

async function logout(page: Page): Promise<void> {
  await closeP2Drawer(page);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}

async function closeP2Drawer(page: Page): Promise<void> {
  const backdrop = page.locator('.p2-drawer-backdrop');
  if (!await backdrop.isVisible()) return;
  const recordClose = backdrop.getByRole('button', { name: 'Close record details' });
  if (await recordClose.isVisible()) await recordClose.click();
  else await backdrop.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(backdrop).toHaveCount(0);
}
