import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
const CUSTOMER_ID = '00000000-0000-4000-8000-000000000501';
const ITEM_ID = '00000000-0000-4000-8000-000000000601';
const CONTRACT_ID = '00000000-0000-4000-8000-000000002501';
const evidenceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../docs/uat/transaction-visibility');

// This test deliberately mutates roles and transaction visibility in the shared
// disposable E2E database. Automatic retries must not inherit those mutations.
test.describe.configure({ retries: 0 });

test('visibility permission combinations govern the sales-order browser workflow', async ({ page }) => {
  test.setTimeout(300_000);
  mkdirSync(evidenceRoot, { recursive: true });
  const suffix = Date.now().toString(36).toUpperCase();
  const orderNumber = `UAT-VIS-${suffix}`;

  await signInAndSelect(page, 'demo.user@qtfoods.local');
  await openSalesOrders(page);
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'New sales order' })).toBeVisible();
  await expect(page.getByLabel('Restrict Entry')).toHaveCount(0);
  await capture(page, '01-neither-permission.png');
  await closeDrawer(page);
  await logout(page);

  await signInAndSelect(page, 'admin.user@qtfoods.local');
  const setup = await createVisibilityRole(page, suffix);
  await logout(page);

  await signInAndSelect(page, 'demo.user@qtfoods.local');
  await openSalesOrders(page);
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await fillFormValue(page, {
    order_number: orderNumber,
    customer_party_id: CUSTOMER_ID,
    sales_lead_id: null,
    sales_contract_id: CONTRACT_ID,
    sales_price_list_id: null,
    order_date: isoDate(0),
    requested_delivery_date: isoDate(3),
    notes: 'Browser UAT visibility evidence.',
    lines: [{ item_id: ITEM_ID, uom_code: 'PACK', quantity: '1', discount_percent: '0' }],
  }, '');
  await page.getByLabel('Restrict Entry').check();
  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('remove it from your operational view immediately');
    await dialog.accept();
  });
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.feedback-toast')).toContainText('saved successfully');
  await page.getByLabel('Sales Orders search').fill(orderNumber);
  await expect(page.locator('.requisition-table tbody')).not.toContainText(orderNumber);
  await expect(page.getByText('Permission-Filtered Operational View')).toBeVisible();
  await capture(page, '02-didnt-only-filtered.png');
  await logout(page);

  await signInAndSelect(page, 'admin.user@qtfoods.local');
  await syncRolePermissions(page, setup.roleId, [setup.showPermissionId], 2);
  await logout(page);

  await signInAndSelect(page, 'demo.user@qtfoods.local');
  await openSalesOrders(page);
  await page.getByLabel('Sales Orders search').fill(orderNumber);
  const showOnlyRow = page.locator('.requisition-table tbody tr').filter({ hasText: orderNumber });
  await expect(showOnlyRow).toBeVisible();
  await expect(showOnlyRow).toContainText('Restricted');
  await showOnlyRow.getByRole('button', { name: 'Open' }).click();
  const showOnlyControl = page.getByRole('region', { name: 'Transaction visibility' });
  await expect(showOnlyControl).toContainText('visible because you have ShowEntries permission');
  await expect(showOnlyControl.getByLabel('Restrict Entry')).toHaveCount(0);
  await capture(page, '03-show-only-read-only.png');
  await closeDrawer(page);
  await logout(page);

  await signInAndSelect(page, 'admin.user@qtfoods.local');
  await syncRolePermissions(page, setup.roleId, [setup.restrictPermissionId, setup.showPermissionId], 3);
  await logout(page);

  await signInAndSelect(page, 'demo.user@qtfoods.local');
  await openSalesOrders(page);
  await page.getByLabel('Sales Orders search').fill(orderNumber);
  const bothRow = page.locator('.requisition-table tbody tr').filter({ hasText: orderNumber });
  await expect(bothRow).toBeVisible();
  await bothRow.getByRole('button', { name: 'Open' }).click();
  const visibilityControl = page.getByRole('region', { name: 'Transaction visibility' });
  const restrictCheckbox = visibilityControl.getByLabel('Restrict Entry');
  await expect(restrictCheckbox).toBeChecked();
  await capture(page, '04-both-permissions-control.png');
  await restrictCheckbox.uncheck();
  await expect(page.locator('.feedback-toast')).toContainText('Entry returned to normal visibility');
  await expect(restrictCheckbox).not.toBeChecked();
  await capture(page, '05-restored-normal.png');
  await closeDrawer(page);
  await logout(page);

  await signInAndSelect(page, 'admin.user@qtfoods.local');
  await apiPost(page, `/api/v1/admin/role-assignments/${setup.assignmentId}`, {
    role_id: setup.roleId,
    is_active: false,
    effective_from: null,
    effective_to: null,
  }, 1);
});

async function createVisibilityRole(page: Page, suffix: string) {
  const workspace = await apiGet<{
    permissions: Array<{ id: string; code: string }>;
  }>(page, '/api/v1/admin/roles');
  const restrictPermissionId = permissionId(workspace.permissions, 'DidntShowCheckbox');
  const showPermissionId = permissionId(workspace.permissions, 'ShowEntries');
  const role = await apiPost<{ data: { id: string } }>(page, '/api/v1/admin/roles', {
    code: `VIS_UAT_${suffix}`,
    name: 'Visibility UAT role',
    description: 'Temporary browser acceptance role for transaction visibility.',
  });
  await syncRolePermissions(page, role.data.id, [restrictPermissionId], 1);
  const assignment = await apiPost<{ data: { id: string } }>(
    page,
    `/api/v1/admin/users/${SALES_USER_ID}/assignments`,
    { role_id: role.data.id, effective_from: null, effective_to: null },
  );

  return { roleId: role.data.id, assignmentId: assignment.data.id, restrictPermissionId, showPermissionId };
}

async function syncRolePermissions(page: Page, roleId: string, permissionIds: string[], version: number) {
  await apiPost(page, `/api/v1/admin/roles/${roleId}/permissions`, { permission_ids: permissionIds }, version);
}

function permissionId(permissions: Array<{ id: string; code: string }>, code: string): string {
  const permission = permissions.find((candidate) => candidate.code === code);
  if (!permission) throw new Error(`Permission ${code} was not exposed by role administration.`);
  return permission.id;
}

async function openSalesOrders(page: Page): Promise<void> {
  await page.getByRole('navigation', { name: 'Main menu' }).locator('[data-screen-code="CRM-ORDER"]').click();
  await expect(page.getByRole('heading', { name: 'Sales orders' })).toBeVisible();
}

async function fillFormValue(page: Page, value: unknown, path: string): Promise<void> {
  if (Array.isArray(value)) {
    const section = page.locator(`section[data-field-path="${path}"]`);
    while (await section.locator('.p2-entry-line-card').count() < value.length) {
      await section.getByRole('button', { name: /^\+ Add / }).click();
    }
    for (let index = 0; index < value.length; index += 1) {
      await fillFormValue(page, value[index], `${path}.${index}`);
    }
    return;
  }
  if (value !== null && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) {
      await fillFormValue(page, item, path ? `${path}.${key}` : key);
    }
    return;
  }

  const control = page.locator(`input[data-field-path="${path}"], select[data-field-path="${path}"], textarea[data-field-path="${path}"]`);
  await expect(control.first(), `Expected a form control for ${path}`).toBeVisible();
  const details = await control.first().evaluate((element) => ({
    tag: element.tagName.toLowerCase(),
    type: element instanceof HTMLInputElement ? element.type : '',
  }));
  // UOM and other derived commercial fields may be rendered read-only.
  // Verify their computed value instead of attempting to edit them.
  if (await control.first().isVisible() && await control.first().getAttribute('readonly') !== null) {
    await expect(control.first()).toHaveValue(value === null ? '' : String(value));
    return;
  }
  if (details.tag === 'select') {
    await control.first().selectOption(value === null ? '' : String(value));
  } else {
    await control.first().fill(value === null ? '' : String(value));
  }
}

async function apiGet<T>(page: Page, path: string): Promise<T> {
  const result = await page.evaluate(async (requestPath) => {
    const response = await fetch(requestPath, { credentials: 'include', headers: { Accept: 'application/json' } });
    return { ok: response.ok, status: response.status, body: await response.json() };
  }, path);
  if (!result.ok) throw new Error(`GET ${path} failed (${result.status}): ${JSON.stringify(result.body)}`);
  return result.body as T;
}

async function apiPost<T = unknown>(page: Page, path: string, body: unknown, version?: number): Promise<T> {
  const csrf = await apiGet<{ data: { csrf_token: string } }>(page, '/api/v1/auth/csrf');
  const result = await page.evaluate(async ({ requestPath, payload, token, idempotencyKey, expectedVersion }) => {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
      'Idempotency-Key': idempotencyKey,
    };
    if (expectedVersion !== undefined) headers['If-Match'] = String(expectedVersion);
    const response = await fetch(requestPath, {
      method: 'POST', credentials: 'include', headers, body: JSON.stringify(payload),
    });
    return { ok: response.ok, status: response.status, body: await response.json() };
  }, { requestPath: path, payload: body, token: csrf.data.csrf_token, idempotencyKey: randomUUID(), expectedVersion: version });
  if (!result.ok) throw new Error(`POST ${path} failed (${result.status}): ${JSON.stringify(result.body)}`);
  return result.body as T;
}

async function closeDrawer(page: Page): Promise<void> {
  const drawer = page.locator('.p2-drawer-backdrop');
  if (!await drawer.isVisible()) return;
  const closeDetails = drawer.getByRole('button', { name: 'Close record details' });
  if (await closeDetails.isVisible()) await closeDetails.click();
  else await drawer.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(drawer).toHaveCount(0);
}

async function logout(page: Page): Promise<void> {
  await closeDrawer(page);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}

async function capture(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: resolve(evidenceRoot, name), fullPage: true });
}

function isoDate(offsetDays: number): string {
  const date = new Date();
  date.setUTCHours(12, 0, 0, 0);
  date.setUTCDate(date.getUTCDate() + offsetDays);
  return date.toISOString().slice(0, 10);
}
