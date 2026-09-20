import { randomUUID } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';

const PASSWORD = 'E2eRoleAccess123';

test('custom roles populate navigation and action authority from different permission sets', async ({ page }, testInfo) => {
  test.setTimeout(240_000);
  const suffix = `${Date.now().toString(36).toUpperCase()}R${testInfo.retry}`;
  const viewerCode = `E2E_LEAD_VIEWER_${suffix}`;
  const creatorCode = `E2E_LEAD_CREATOR_${suffix}`;
  const orderViewerCode = `E2E_ORDER_VIEWER_${suffix}`;
  const viewerEmail = `e2e.lead.viewer.${suffix.toLowerCase()}@qtfoods.local`;
  const creatorEmail = `e2e.lead.creator.${suffix.toLowerCase()}@qtfoods.local`;

  await loginAndSelect(page, 'admin.user@qtfoods.local', 'prototype');

  const roleWorkspace = await apiGet<RoleWorkspace>(page, '/api/v1/admin/roles');
  const leadView = permissionId(roleWorkspace, 'SCREEN:CRM-LEAD:VIEW');
  const leadCreate = permissionId(roleWorkspace, 'ACTION:CRM-LEAD:CREATE');
  const orderView = permissionId(roleWorkspace, 'SCREEN:CRM-ORDER:VIEW');

  const viewerRole = await apiPost<CommandResult>(page, '/api/v1/admin/roles', {
    code: viewerCode,
    name: `E2E Lead Viewer ${suffix}`,
    description: 'Can read enquiries but cannot create them.',
  });
  await apiPost(page, `/api/v1/admin/roles/${viewerRole.data.id}/permissions`, {
    permission_ids: [leadView],
  }, viewerRole.data.record_version);

  const creatorRole = await apiPost<CommandResult>(page, '/api/v1/admin/roles', {
    code: creatorCode,
    name: `E2E Lead Creator ${suffix}`,
    description: 'Can read and create enquiries.',
  });
  await apiPost(page, `/api/v1/admin/roles/${creatorRole.data.id}/permissions`, {
    permission_ids: [leadView, leadCreate],
  }, creatorRole.data.record_version);

  const orderViewerRole = await apiPost<CommandResult>(page, '/api/v1/admin/roles', {
    code: orderViewerCode,
    name: `E2E Order Viewer ${suffix}`,
    description: 'Can read sales orders only.',
  });
  await apiPost(page, `/api/v1/admin/roles/${orderViewerRole.data.id}/permissions`, {
    permission_ids: [orderView],
  }, orderViewerRole.data.record_version);

  const viewerUser = await apiPost<UserCommandResult>(page, '/api/v1/admin/users', {
    email: viewerEmail,
    name: `E2E Lead Viewer ${suffix}`,
    temporary_password: PASSWORD,
    role_id: viewerRole.data.id,
    effective_from: null,
    effective_to: null,
  });

  await apiPost(page, `/api/v1/admin/users/${viewerUser.data.id}/assignments`, {
    role_id: orderViewerRole.data.id,
    effective_from: null,
    effective_to: null,
  });

  await apiPost(page, '/api/v1/admin/users', {
    email: creatorEmail,
    name: `E2E Lead Creator ${suffix}`,
    temporary_password: PASSWORD,
    role_id: creatorRole.data.id,
    effective_from: null,
    effective_to: null,
  });

  await openRoleRegister(page, viewerCode);
  await expect(page.locator('.admin-table tbody tr').filter({ hasText: viewerCode })).toContainText('1');

  await logout(page);
  await loginAndSelect(page, viewerEmail, PASSWORD);

  const viewerNav = page.getByRole('navigation', { name: 'Main menu' });
  await expect(viewerNav.locator('[data-screen-code="CRM-LEAD"]')).toBeVisible();
  await expect(viewerNav.locator('[data-screen-code="CRM-ORDER"]')).toBeVisible();
  await expect(viewerNav.locator('[data-screen-code="ADM-USER"]')).toHaveCount(0);

  await viewerNav.locator('[data-screen-code="CRM-LEAD"]').click();
  await expect(page.getByRole('heading', { name: 'Leads & Enquiries' })).toBeVisible();
  await expect(page.getByRole('button', { name: '+ New', exact: true })).toHaveCount(0);
  expect(await apiPostStatus(page, '/api/v1/sales/leads', {})).toBe(403);

  await logout(page);
  await loginAndSelect(page, creatorEmail, PASSWORD);

  const creatorNav = page.getByRole('navigation', { name: 'Main menu' });
  await expect(creatorNav.locator('[data-screen-code="CRM-LEAD"]')).toBeVisible();
  await expect(creatorNav.locator('[data-screen-code="CRM-ORDER"]')).toHaveCount(0);
  await creatorNav.locator('[data-screen-code="CRM-LEAD"]').click();
  await expect(page.getByRole('heading', { name: 'Leads & Enquiries' })).toBeVisible();
  await expect(page.getByRole('button', { name: '+ New', exact: true })).toBeVisible();
  expect(await apiPostStatus(page, '/api/v1/sales/leads', {})).toBe(422);

  // Add CREATE to the existing viewer role and prove the authority appears after a fresh session.
  await logout(page);
  await loginAndSelect(page, 'admin.user@qtfoods.local', 'prototype');
  await apiPost(page, `/api/v1/admin/roles/${viewerRole.data.id}/permissions`, {
    permission_ids: [leadView, leadCreate],
  }, 2);

  await logout(page);
  await loginAndSelect(page, viewerEmail, PASSWORD);
  const updatedViewerNav = page.getByRole('navigation', { name: 'Main menu' });
  await updatedViewerNav.locator('[data-screen-code="CRM-LEAD"]').click();
  await expect(page.getByRole('button', { name: '+ New', exact: true })).toBeVisible();
  expect(await apiPostStatus(page, '/api/v1/sales/leads', {})).toBe(422);
});

type RoleWorkspace = {
  permissions: Array<{ id: string; code: string; status: string }>;
};

type CommandResult = {
  data: { id: string; record_version: number };
};

type UserCommandResult = {
  data: { id: string; role_assignment_id: string; record_version: number };
};

function permissionId(workspace: RoleWorkspace, code: string): string {
  const permission = workspace.permissions.find((item) => item.code === code && item.status === 'ACTIVE');
  if (!permission) throw new Error(`Expected active permission ${code} in the role catalogue.`);
  return permission.id;
}

async function openRoleRegister(page: Page, code: string): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="ADM-ROLE"]').click();
  await expect(page.getByRole('heading', { name: 'Roles & Permissions' })).toBeVisible();
  await page.getByLabel('Search roles').fill(code);
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('.admin-table tbody tr').filter({ hasText: code })).toBeVisible();
}

async function apiGet<T>(page: Page, path: string): Promise<T> {
  const result = await page.evaluate(async (requestPath) => {
    const response = await fetch(requestPath, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
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
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify(payload),
    });
    return { ok: response.ok, status: response.status, body: await response.json() };
  }, {
    requestPath: path,
    payload: body,
    token: csrf.data.csrf_token,
    idempotencyKey: randomUUID(),
    expectedVersion: version,
  });
  if (!result.ok) throw new Error(`POST ${path} failed (${result.status}): ${JSON.stringify(result.body)}`);
  return result.body as T;
}

async function apiPostStatus(page: Page, path: string, body: unknown): Promise<number> {
  const csrf = await apiGet<{ data: { csrf_token: string } }>(page, '/api/v1/auth/csrf');
  return page.evaluate(async ({ requestPath, payload, token, idempotencyKey }) => {
    const response = await fetch(requestPath, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify(payload),
    });
    return response.status;
  }, { requestPath: path, payload: body, token: csrf.data.csrf_token, idempotencyKey: randomUUID() });
}

async function loginAndSelect(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('button', { name: /Training Plant/ }).click();
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}
