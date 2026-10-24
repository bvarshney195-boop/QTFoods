import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const DOCUMENT_NUMBER = 'E2E-PORTAL-OUT-001';
const ACKNOWLEDGEMENT_REFERENCE = 'E2E-PORTAL-RECEIPT-001';

test('partner portal publishes into one customer tenant and records receipt without internal approval', async ({ page }) => {
  test.setTimeout(180_000);

  await loginAndSelect(page, 'admin.user@qtfoods.local', 'Training Plant');
  await openPortal(page);
  await expect(page.getByText('Internal administration view.')).toBeVisible();
  await page.getByRole('button', { name: '+ Publish document', exact: true }).click();

  const customer = page.getByLabel('Document customer tenant');
  const northMarket = customer.locator('option').filter({ hasText: 'North Market' });
  await expect(northMarket).toHaveCount(1);
  await customer.selectOption(await northMarket.getAttribute('value') ?? '');
  await page.getByLabel('Partner document number').fill(DOCUMENT_NUMBER);
  await page.getByLabel('Partner document type').selectOption('GENERAL');
  await page.getByLabel('Partner document title').fill('Portal browser hand-off');
  await page.getByLabel('Partner private document').setInputFiles({
    name: 'portal-e2e.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('Private tenant document for the partner portal E2E workflow.\n'),
  });
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('status')).toContainText('Outbound document published privately.');
  await expect(page.locator('.requisition-table tbody tr').filter({ hasText: DOCUMENT_NUMBER })).toBeVisible();
  await logout(page);

  await loginAndSelect(page, 'partner.user@qtfoods.local', 'Training Plant');
  await openPortal(page);
  await expect(page.locator('.p2-live-notice')).toContainText('North Market Distributor');
  await expect(page.locator('.p2-live-notice')).toContainText('acknowledgements record receipt only');

  const documentRow = page.locator('.requisition-table tbody tr').filter({ hasText: DOCUMENT_NUMBER });
  await expect(documentRow).toBeVisible();
  await documentRow.getByRole('button', { name: 'Open' }).click();
  await expect(page.getByText(/^[a-f0-9]{64}$/)).toBeVisible();

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download', exact: true }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe('portal-e2e.txt');
  await expect(page.getByRole('status')).toContainText('permission and party-scope verification');

  await page.getByRole('button', { name: 'Acknowledge', exact: true }).click();
  await page.getByLabel('Acknowledgement reference').fill(ACKNOWLEDGEMENT_REFERENCE);
  await expect(page.getByText('It does not approve an order, claim, invoice, or internal workflow.')).toBeVisible();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('status')).toContainText('without internal approval effect');
  await expect(page.locator('.requisition-table tbody tr').filter({ hasText: DOCUMENT_NUMBER })).toContainText('Acknowledged');

  await page.getByRole('button', { name: 'Shipments', exact: true }).click();
  await expect(page.getByText('SHP-2026-0001', { exact: true })).toBeVisible();
  await expect(page.getByText('SHP-2026-0002', { exact: true })).toHaveCount(0);
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
});

async function openPortal(page: Page): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="PORTAL-EXT"]').click();
  await expect(page.getByRole('heading', { name: 'Partner Portal' })).toBeVisible();
  await expect(page.locator('.p2-live-notice')).toBeVisible();
}

async function loginAndSelect(page: Page, email: string, plant: string): Promise<void> {
  await signInAndSelect(page, email, 'prototype', plant);
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}
