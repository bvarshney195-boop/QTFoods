import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('ERP Administrator versions approval policy and delegates scoped authority', async ({ page }) => {
  await loginAndSelect(page, 'admin.user@qtfoods.local');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });

  await navigation.locator('[data-screen-code="ADM-RULE"]').click();
  await expect(page.getByRole('heading', { name: 'Approval Rules' })).toBeVisible();
  await expect(page.getByText('Server-authoritative approval policy')).toBeVisible();

  const unsoldReturnRule = page.locator('.approval-rule-table tbody tr').filter({
    hasText: 'Unsold return loss approval',
  }).filter({
    has: page.getByText('PLANT', { exact: true }),
  });
  await unsoldReturnRule.getByRole('button', { name: 'Open' }).click();

  const editor = page.locator('.approval-rule-editor');
  await expect(editor.getByRole('heading', { name: 'Unsold return loss approval' })).toBeVisible();
  await editor.getByLabel('Description').fill(
    'E2E-verified plant policy routing loss disposition by destroyed base quantity.'
  );
  await editor.getByLabel('Band 1 due hours').fill('23');
  await editor.getByLabel('Band 1 escalate after hours').fill('12');
  await editor.getByRole('button', { name: 'Save policy version' }).click();
  await expect(page.getByRole('status')).toContainText(/saved as policy version \d+/);
  await expect(editor.getByText(/PLANT · v\d+/)).toBeVisible();

  await page.getByLabel('Delegator').selectOption({
    label: 'Demo Finance Manager (finance.user@qtfoods.local)',
  });
  await page.getByLabel('Delegate', { exact: true }).selectOption({
    label: 'Demo Operations Manager (operations.user@qtfoods.local)',
  });
  await page.getByLabel('Approval authority').selectOption('ACTION:RET-UNSOLD:APPROVE');
  await page.getByLabel('Delegation reason').fill('E2E reviewer cover for the Training Plant.');
  await page.getByRole('button', { name: 'Create delegation' }).click();
  await expect(page.getByRole('status')).toContainText('Temporary approval authority delegated');

  const delegation = page.locator('.delegation-table tbody tr').filter({
    hasText: 'E2E reviewer cover for the Training Plant.',
  });
  await expect(delegation).toContainText('Demo Finance Manager');
  await expect(delegation).toContainText('Demo Operations Manager');
  await expect(delegation).toContainText('Active');

  await page.getByRole('button', { name: 'Escalate due approvals' }).click();
  await expect(page.getByRole('status')).toContainText('0 overdue approvals routed');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();

  await loginAndSelect(page, 'operations.user@qtfoods.local');
  const operationsNavigation = page.getByRole('navigation', { name: 'Main menu' });
  await operationsNavigation.locator('[data-screen-code="RET-UNSOLD"]').click();
  await expect(page.getByRole('heading', { name: 'Unsold Sales Return & Loss' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Loss disposition approval inbox' })).toBeVisible();
  await expect(page.getByText('No pending loss dispositions in this context.')).toBeVisible();
});

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await signInAndSelect(page, email);
}
