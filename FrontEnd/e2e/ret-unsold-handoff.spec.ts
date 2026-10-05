import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const partyId = '00000000-0000-4000-8000-000000000501';
const shipmentId = '00000000-0000-4000-8000-000000001001';
const shipmentLineId = '00000000-0000-4000-8000-000000001101';
const returnPositionId = '00000000-0000-4000-8000-000000001201';
const invoiceId = '00000000-0000-4000-8000-000000001301';
const evidenceName = 'e2e-return-confirmation.txt';
const evidenceContents = 'Distributor confirmed the E2E return collection.'.padEnd(1200, '.');

test('Sales, Operations, and Finance complete an unsold-return hand-off', async ({
  page,
}) => {
  await loginAs(page, 'demo.user@qtfoods.local');

  await page.getByLabel('Customer / Distributor').selectOption(partyId);
  await expect(
    page.getByLabel('Original shipment').locator('option[value="' + shipmentId + '"]')
  ).toHaveCount(1);
  await page.getByLabel('Original shipment').selectOption(shipmentId);
  await expect(
    page
      .getByLabel('Shipment SKU / finished-goods lot')
      .locator('option[value="' + shipmentLineId + '"]')
  ).toHaveCount(1);
  await page
    .getByLabel('Shipment SKU / finished-goods lot')
    .selectOption(shipmentLineId);
  await page.getByLabel('Unsold quantity').fill('10');
  await page.getByLabel('Expected return date').fill(tomorrow());
  await page.getByLabel('Sales note').fill('Created by the multi-role browser test.');
  await page.getByRole('button', { name: 'Submit return request' }).click();

  const creation = page
    .locator('.form-success')
    .filter({ hasText: /created in REQUESTED state/ });
  await expect(creation).toBeVisible();
  const creationText = await creation.textContent();
  const caseMatch = creationText?.match(/Return (RET-[0-9A-F]{8}) created/);
  expect(caseMatch, 'created case identifier').not.toBeNull();
  const caseCode = caseMatch![1];

  const salesCase = await caseWorkspace(page, caseCode);
  await salesCase.getByLabel('File').setInputFiles({
    name: evidenceName,
    mimeType: 'text/plain',
    buffer: Buffer.from(evidenceContents),
  });
  await salesCase
    .getByLabel('Evidence note')
    .fill('Sales confirmation for cross-role review');
  await salesCase.getByRole('button', { name: 'Attach evidence' }).click();
  await expect(salesCase.getByRole('status')).toContainText(
    evidenceName + ' attached and retained through'
  );
  await expect(salesCase.getByText(evidenceName, { exact: true })).toBeVisible();
  await logout(page);

  await loginAs(page, 'operations.user@qtfoods.local');
  const operationsCase = await openCase(page, caseCode);
  await operationsCase.getByLabel('Received').fill('10');
  await operationsCase
    .getByLabel('Quarantine position')
    .selectOption(returnPositionId);
  await operationsCase
    .getByRole('button', { name: 'Post quarantine receipt' })
    .click();
  await expect(operationsCase.getByRole('status')).toContainText(
    'Physical receipt recorded'
  );

  await expect(
    operationsCase.getByRole('button', { name: 'Submit for loss approval' })
  ).toBeVisible();
  await operationsCase
    .getByLabel('restock quantity for SKU-APPLE-100')
    .fill('6');
  await operationsCase
    .getByLabel('destroy quantity for SKU-APPLE-100')
    .fill('4');
  await operationsCase
    .getByLabel('Quality reason for SKU-APPLE-100')
    .selectOption('DAMAGED_RETURN');
  await operationsCase
    .getByRole('button', { name: 'Submit for loss approval' })
    .click();
  await expect(operationsCase.getByRole('status')).toContainText(
    'Quality disposition submitted for approval'
  );
  await logout(page);

  await loginAs(page, 'finance.user@qtfoods.local', false);
  const approvalWork = page.locator('.work-table tbody tr').filter({
    hasText: 'Unsold return loss approval',
  });
  await expect(approvalWork).toBeVisible();
  await approvalWork.getByRole('button', { name: 'Claim' }).click();
  await expect(approvalWork).toContainText('Demo Finance Manager');
  await approvalWork.getByRole('button', { name: 'Open' }).click();
  await expect(
    page.getByRole('heading', { name: 'Unsold returns' })
  ).toBeVisible();
  await caseWorkspace(page, caseCode);

  const approvalInbox = page.locator('.approval-inbox');
  await expect(
    approvalInbox.getByRole('button', { name: 'Approve disposition' })
  ).toBeVisible();
  await approvalInbox
    .getByLabel('Reviewer note / rejection reason')
    .fill('Finance reviewed the Quality disposition.');
  await approvalInbox
    .getByRole('button', { name: 'Approve disposition' })
    .click();

  const financeCase = await caseWorkspace(page, caseCode);
  await expect(
    financeCase.getByRole('button', { name: 'Post approved loss' })
  ).toBeVisible();
  await financeCase.getByLabel('Cost amount').fill('120');
  await financeCase.getByRole('button', { name: 'Post approved loss' }).click();
  await expect(
    financeCase.getByRole('button', { name: 'Confirm invoice' })
  ).toBeVisible();

  await financeCase.getByLabel('Source invoice').selectOption(invoiceId);
  await financeCase.getByRole('button', { name: 'Confirm invoice' }).click();
  await expect(
    financeCase.getByRole('button', { name: 'Post net credit' })
  ).toBeVisible();

  await financeCase.getByLabel('Credit-note number').fill('CN-E2E-0001');
  await financeCase.getByLabel('Net credit amount').fill('100');
  await financeCase.getByRole('button', { name: 'Post net credit' }).click();
  await expect(
    financeCase.getByRole('button', { name: 'Record tax review' })
  ).toBeVisible();

  await financeCase.getByLabel('Tax document number').fill('TAX-E2E-0001');
  await financeCase.getByLabel('Tax adjustment amount').fill('18');
  await financeCase.getByLabel('Tax code').fill('GST18');
  await financeCase.getByRole('button', { name: 'Record tax review' }).click();
  await expect(
    financeCase.getByRole('button', { name: 'Complete finance resolution' })
  ).toBeVisible();

  await financeCase
    .getByLabel('Settlement path')
    .selectOption('receivable-adjustment');
  await financeCase
    .getByLabel('Settlement reference')
    .fill('AR-E2E-0001');
  await financeCase
    .getByRole('button', { name: 'Complete finance resolution' })
    .click();

  await expect(
    financeCase.getByText('Value treatment completed', { exact: true })
  ).toBeVisible();
  await expect(
    financeCase
      .locator('.finance-summary')
      .getByText('RECEIVABLE ADJUSTMENT', { exact: true })
  ).toBeVisible();

  const evidenceRow = financeCase.locator('tr').filter({
    hasText: evidenceName,
  });
  const downloadPromise = page.waitForEvent('download');
  await evidenceRow.getByRole('button', { name: 'Download' }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe(evidenceName);
  const downloadPath = await download.path();
  expect(downloadPath).not.toBeNull();
  expect(await readFile(downloadPath!, 'utf8')).toBe(evidenceContents);
});

async function loginAs(page: Page, email: string, openReturns = true): Promise<void> {
  await signInAndSelect(page, email);

  if (!openReturns) {
    await expect(page.getByRole('heading', { name: 'My work' })).toBeVisible();
    return;
  }

  const navigation = page.getByRole('navigation', {
    name: 'Main menu',
  });
  await navigation.locator('[data-screen-code="RET-UNSOLD"]').click();
  await expect(
    page.getByRole('heading', { name: 'Unsold returns' })
  ).toBeVisible();
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}

async function openCase(page: Page, caseCode: string) {
  await page.getByRole('button', { name: caseCode, exact: true }).click();
  return caseWorkspace(page, caseCode);
}

async function caseWorkspace(page: Page, caseCode: string) {
  const workspace = page.locator('.case-workspace');
  await expect(
    workspace.getByRole('heading', { name: new RegExp(caseCode) })
  ).toBeVisible();
  return workspace;
}

function tomorrow(): string {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + 1);
  return date.toISOString().slice(0, 10);
}
