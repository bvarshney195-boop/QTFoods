import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('ERP Administrator provisions plant foundation data and effective user authority', async ({ page }) => {
  await login(page, 'admin.user@qtfoods.local', 'prototype');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="ADM-ORG"]').click();
  await expect(page.getByRole('heading', { name: 'Companies & plants' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Plant code').fill('E2E-PLANT');
  await page.getByLabel('Plant name').fill('E2E Pilot Plant');
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Plant created');
  await expect(page.getByText('E2E Pilot Plant', { exact: true })).toBeVisible();

  await navigation.locator('[data-screen-code="ADM-LOC"]').click();
  await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Location code').fill('E2E-BULK');
  await page.getByLabel('Name').fill('E2E Bulk Store');
  await page.locator('.admin-editor').getByLabel('Location type').selectOption('WAREHOUSE');
  await page.getByRole('button', { name: 'Create location' }).click();
  await expect(page.getByRole('status')).toContainText('E2E-BULK was created');

  const locationRow = page.locator('.admin-table tbody tr').filter({ hasText: 'E2E-BULK' });
  await expect(locationRow).toContainText('E2E Bulk Store');
  await locationRow.getByRole('button', { name: 'Open' }).click();
  await page.getByLabel('Name').fill('E2E Bulk Warehouse');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByRole('status')).toContainText('saved with a new record version');
  await expect(locationRow).toContainText('E2E Bulk Warehouse');

  await navigation.locator('[data-screen-code="ADM-ROLE"]').click();
  await expect(page.getByRole('heading', { name: 'Roles & access' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Code').fill('E2E_LOCATION_AUDITOR');
  await page.getByLabel('Name').fill('E2E Location Auditor');
  await page.getByLabel('Description').fill('Reads the Training Plant location hierarchy.');
  await page.getByRole('button', { name: 'Create definition' }).click();
  await expect(page.getByRole('status')).toContainText('Scoped custom role created');

  const roleRow = page.locator('.admin-table tbody tr').filter({ hasText: 'E2E_LOCATION_AUDITOR' });
  await roleRow.getByRole('button', { name: 'Open' }).click();
  await page.getByRole('checkbox', { name: /View ADM-LOC/ }).check();
  await page.getByRole('button', { name: 'Save permissions' }).click();
  await expect(page.getByRole('status')).toContainText('permissions were replaced atomically');

  await navigation.locator('[data-screen-code="ADM-USER"]').click();
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Name').fill('E2E Location Auditor');
  await page.getByLabel('Email', { exact: true }).fill('e2e.location.auditor@qtfoods.local');
  await page.getByLabel('Initial role').selectOption({
    label: 'E2E Location Auditor (E2E_LOCATION_AUDITOR)',
  });
  await page.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByRole('status')).toContainText('Invitation and initial plant role assignment');
  await expect(page.getByText('e2e.location.auditor@qtfoods.local', { exact: true })).toBeVisible();
  const invitationUrl = await page.getByRole('link', { name: 'Open development email link' }).getAttribute('href');
  expect(invitationUrl).toBeTruthy();

  await page.getByRole('button', { name: 'Sign out' }).click();
  await page.goto(invitationUrl!);
  await expect(page.getByRole('heading', { name: 'Accept invitation' })).toBeVisible();
  await expect(page.getByText('E2E Location Auditor', { exact: true })).toBeVisible();
  await page.getByLabel('New password', { exact: true }).fill('E2eTemporary123');
  await page.getByLabel('Confirm new password', { exact: true }).fill('E2eTemporary123');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByRole('status')).toContainText('Invitation accepted');
  await login(page, 'e2e.location.auditor@qtfoods.local', 'E2eTemporary123');
  await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Main menu' })
    .locator('[data-screen-code="ADM-LOC"]')).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Main menu' })
    .locator('[data-screen-code="ADM-USER"]')).toHaveCount(0);
});

async function login(page: Page, email: string, password: string): Promise<void> {
  await signInAndSelect(page, email, password);
}
