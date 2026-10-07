import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('ERP Administrator provisions plant foundation data and effective user authority', async ({ page }) => {
  await login(page, 'admin.user@qtfoods.local', 'prototype');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="ADM-ORG"]').click();
  await expect(page.getByRole('heading', { name: 'Companies & plants' })).toBeVisible();
  const organisationWorkspace = page.getByTestId('organisation-workspace');
  await expect.poll(() => organisationWorkspace.evaluate((element) =>
    getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length
  )).toBe(1);
  const registerBox = await organisationWorkspace.locator(':scope > section').boundingBox();
  const editorBox = await organisationWorkspace.locator(':scope > aside').boundingBox();
  expect(registerBox).not.toBeNull();
  expect(editorBox).not.toBeNull();
  expect(editorBox!.y).toBeGreaterThanOrEqual(registerBox!.y + registerBox!.height);
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

test('User register issues temporary passwords and governs MFA and deletion', async ({ page }) => {
  await login(page, 'admin.user@qtfoods.local', 'prototype');
  await page.getByRole('navigation', { name: 'Main menu' }).locator('[data-screen-code="ADM-USER"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByRole('button', { name: 'Direct account' }).click();
  await page.getByLabel('Name').fill('E2E Governed User');
  await page.getByLabel('Email', { exact: true }).fill('e2e.governed.user@qtfoods.local');
  await page.getByLabel(/Temporary password/).fill('InitialPass123');
  await page.getByLabel('Initial role').selectOption({ label: 'Sales Manager (SALES_MANAGER)' });
  await page.getByRole('button', { name: 'Create user' }).click();
  await expect(page.getByRole('status')).toContainText('created atomically');

  const row = page.locator('.admin-table tbody tr').filter({ hasText: 'e2e.governed.user@qtfoods.local' });
  await row.getByRole('button', { name: 'Open' }).click();
  await page.getByRole('button', { name: 'Reset password' }).click();
  const resetDialog = page.getByRole('dialog', { name: 'Reset password for E2E Governed User' });
  await resetDialog.getByLabel(/^Temporary password/).fill('ResetPass9x');
  await resetDialog.getByLabel('Confirm temporary password').fill('ResetPass9x');
  await resetDialog.getByRole('button', { name: 'Issue temporary password' }).click();
  await expect(page.getByRole('status')).toContainText('must replace it at next sign-in');

  await page.getByRole('button', { name: 'Enable MFA' }).click();
  const enableDialog = page.getByRole('alertdialog', { name: 'Enable MFA for E2E Governed User?' });
  await enableDialog.getByRole('button', { name: 'Enable MFA' }).click();
  await expect(page.getByRole('status')).toContainText('MFA is now required');

  await page.getByRole('button', { name: 'Disable MFA' }).click();
  const disableDialog = page.getByRole('alertdialog', { name: 'Disable MFA for E2E Governed User?' });
  await disableDialog.getByRole('button', { name: 'Disable MFA' }).click();
  await expect(page.getByRole('status')).toContainText('MFA was disabled');

  await page.getByRole('button', { name: 'Delete user' }).click();
  const deleteDialog = page.getByRole('alertdialog', { name: 'Delete E2E Governed User?' });
  await deleteDialog.getByRole('button', { name: 'Delete user' }).click();
  await expect(page.getByRole('status')).toContainText('Audit history was retained');
  await expect(row.getByText('Deleted', { exact: true })).toBeVisible();
});

async function login(page: Page, email: string, password: string): Promise<void> {
  await signInAndSelect(page, email, password);
}
