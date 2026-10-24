import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const REPORT_RUN = 'E2E-REPORT-001';
const SUPPORT_SUBJECT = 'E2E reporting evidence needs clarification';

test('finance reporting and requester-to-manager support handoff are live and governed', async ({ page }) => {
  test.setTimeout(180_000);

  await loginAndSelect(page, 'finance.user@qtfoods.local');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="BI-REP"]').click();
  await expect(page.getByRole('heading', { name: 'Reports' })).toBeVisible();
  await expect(page.getByText(/Report runs never refresh in place/)).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Report run number').fill(REPORT_RUN);
  await page.getByLabel('Report definition', { exact: true }).selectOption('TRIAL_BALANCE');
  await page.getByRole('button', { name: 'Generate immutable snapshot' }).click();
  await expect(page.getByRole('status')).toContainText('TRIAL_BALANCE snapshot generated');
  await expect(page.locator('.reporting-detail')).toContainText(REPORT_RUN);
  await page.getByText('Export details and integrity', { exact: true }).click();
  await expect(page.getByText(/^[a-f0-9]{64}$/)).toBeVisible();
  await page.getByRole('button', { name: 'Download CSV' }).click();
  await expect(page.getByRole('status')).toContainText('CSV export created from stored snapshot rows');
  await expect(page.locator('.reporting-detail')).toContainText('CSV');
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
  await logout(page);

  await loginAndSelect(page, 'demo.user@qtfoods.local');
  await openHelp(page);
  await expect(page.getByText(/Knowledge articles follow your effective screen access/)).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByLabel('Support case category').selectOption('REPORTING');
  await page.getByLabel('Support case priority').selectOption('HIGH');
  await page.getByLabel('Affected screen').selectOption('BI-PROFIT');
  await page.getByLabel('Support case subject').fill(SUPPORT_SUBJECT);
  await page.getByLabel('Support case description').fill('The saved profitability result needs a cutoff and source-freshness explanation for review.');
  await page.getByRole('button', { name: 'Open support case' }).click();
  await expect(page.getByRole('status')).toContainText('opened in the selected plant support queue');
  await expect(page.locator('.help-case-detail')).toContainText(SUPPORT_SUBJECT);
  await expect(page.locator('.help-case-detail')).toContainText('Open');
  await logout(page);

  await loginAndSelect(page, 'admin.user@qtfoods.local');
  await openHelp(page);
  await page.getByRole('tab', { name: 'Support queue' }).click();
  await page.getByLabel('Help search').fill(SUPPORT_SUBJECT);
  const managerRow = page.locator('.help-case-register tbody tr').filter({ hasText: SUPPORT_SUBJECT });
  await expect(managerRow).toBeVisible();
  await managerRow.getByRole('button', { name: 'Open' }).click();
  await page.getByRole('button', { name: 'Start support work' }).click();
  await expect(page.getByRole('status')).toContainText('Support ownership accepted');
  await page.getByRole('button', { name: 'Propose resolution' }).click();
  await page.getByLabel('Resolution summary').fill('The report detail now records both the immutable cutoff and the latest included source timestamp.');
  await page.getByRole('button', { name: 'Record proposed resolution' }).click();
  await expect(page.getByRole('status')).toContainText('Resolution recorded for requester confirmation');
  await expect(page.locator('.help-case-detail')).toContainText('Resolved');
  await logout(page);

  await loginAndSelect(page, 'demo.user@qtfoods.local');
  await openHelp(page);
  await page.getByRole('tab', { name: 'My support cases' }).click();
  await page.getByLabel('Help search').fill(SUPPORT_SUBJECT);
  const requesterRow = page.locator('.help-case-register tbody tr').filter({ hasText: SUPPORT_SUBJECT });
  await expect(requesterRow).toBeVisible();
  await requesterRow.getByRole('button', { name: 'Open' }).click();
  await expect(page.locator('.help-timeline')).toContainText('Started');
  await expect(page.locator('.help-timeline')).toContainText('Resolved');
  await page.getByRole('button', { name: 'Confirm & close' }).click();
  await page.getByRole('textbox', { name: 'Confirmation', exact: true }).fill('Confirmed that cutoff and source freshness are visible in the report evidence.');
  await page.getByRole('button', { name: 'Confirm and close' }).click();
  await expect(page.getByRole('status')).toContainText('closed the case');
  await expect(page.locator('.help-case-detail')).toContainText('Closed');
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
});

async function openHelp(page: Page): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="ADM-HELP"]').click();
  await expect(page.getByRole('heading', { name: 'Help & support' })).toBeVisible();
}

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await signInAndSelect(page, email);
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}
