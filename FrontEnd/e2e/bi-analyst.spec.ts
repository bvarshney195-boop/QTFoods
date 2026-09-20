import { randomUUID } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';

test('BI Analyst sees only BI workspaces and can run/export reports without transactional authority', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  const suffix = testInfo.retry === 0 ? '001' : `R${testInfo.retry}`;
  const runNumber = `E2E-BI-INV-${suffix}`;

  await loginAndSelect(page, 'bi.user@qtfoods.local', 'prototype');

  const session = await apiGet<SessionPayload>(page, '/api/v1/me');
  expect(session.data.roles).toEqual(['BI_ANALYST']);
  expect(session.data.allowed_screens).toEqual(['BI-PROFIT', 'BI-REP']);
  expect(session.data.allowed_actions).toEqual(['ACTION:BI-REP:EXPORT', 'ACTION:BI-REP:RUN']);

  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await expect(navigation.locator('[data-screen-code="BI-REP"]')).toBeVisible();
  await expect(navigation.locator('[data-screen-code="BI-PROFIT"]')).toBeVisible();
  await expect(navigation.locator('[data-screen-code="CRM-ORDER"]')).toHaveCount(0);
  await expect(navigation.locator('[data-screen-code="FIN-GL"]')).toHaveCount(0);
  await expect(navigation.locator('[data-screen-code="INV-STK"]')).toHaveCount(0);
  await expect(navigation.locator('[data-screen-code="ADM-USER"]')).toHaveCount(0);

  await navigation.locator('[data-screen-code="BI-REP"]').click();
  await expect(page.getByRole('heading', { name: 'Controlled Reports' })).toBeVisible();
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await page.getByLabel('Report run number').fill(runNumber);
  await page.getByLabel('Report definition').selectOption('INVENTORY_AVAILABILITY');
  await page.getByRole('button', { name: 'Generate immutable snapshot' }).click();
  await expect(page.getByRole('status')).toContainText('INVENTORY_AVAILABILITY snapshot generated');

  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Create & download CSV' }).click();
  expect((await download).suggestedFilename()).toBe(`${runNumber.toLowerCase()}.csv`);
  await expect(page.getByRole('status')).toContainText('CSV export created');

  await navigation.locator('[data-screen-code="BI-PROFIT"]').click();
  await expect(page.getByRole('heading', { name: 'Order Profitability' })).toBeVisible();
  await expect(page.locator('.p2-live-notice')).toContainText('never posts or changes the ledger');

  expect(await apiPostStatus(page, '/api/v1/sales/leads', {})).toBe(403);
  expect(await apiPostStatus(page, '/api/v1/finance/journals', {})).toBe(403);
  expect(await apiPostStatus(page, '/api/v1/inventory/issues', {})).toBe(403);
});

type SessionPayload = {
  data: {
    roles: string[];
    allowed_screens: string[];
    allowed_actions: string[];
  };
};

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
  }, {
    requestPath: path,
    payload: body,
    token: csrf.data.csrf_token,
    idempotencyKey: randomUUID(),
  });
}

async function loginAndSelect(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('button', { name: /Training Plant/ }).click();
}
