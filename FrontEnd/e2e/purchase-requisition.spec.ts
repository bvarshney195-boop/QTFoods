import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

const RAW_ITEM = '00000000-0000-4000-8000-000000000603';
const WESTERN_SUPPLIER = '00000000-0000-4000-8000-000000000503';
const DECCAN_SUPPLIER = '00000000-0000-4000-8000-000000000504';

test('Operations sources an approved requisition and controls its purchase order', async ({ page }) => {
  const requestedDate = dateFromToday(0);
  const responseDueDate = dateFromToday(7);
  const westernDeliveryDate = dateFromToday(24);
  const deccanDeliveryDate = dateFromToday(23);
  const requiredByDate = dateFromToday(30);

  await loginAndSelect(page, 'operations.user@qtfoods.local');
  let navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="PUR-REQ"]').click();
  await expect(page.getByRole('heading', { name: 'Purchase requisitions' })).toBeVisible();

  await page.getByRole('button', { name: '+ New' }).click();
  const editor = page.locator('.requisition-editor');
  await editor.getByLabel('Requisition number').fill('E2E-REQ-001');
  await editor.getByLabel('Department').fill('Production');
  await editor.getByLabel('Purpose').fill('Replenish apple ingredient stock for the production plan.');
  await editor.getByLabel('Requested date').fill(requestedDate);
  await editor.getByLabel('Required by date').fill(requiredByDate);
  await editor.getByLabel('Line 1 item').selectOption(RAW_ITEM);
  await editor.getByLabel('Line 1 quantity').fill('20');
  await editor.getByLabel('Line 1 estimated unit cost').fill('50');
  await editor.getByRole('button', { name: 'Create draft' }).click();
  await expect(page.getByRole('status')).toContainText('Draft requisition created');
  await expect(editor).toContainText('E2E-REQ-001');

  await editor.getByRole('button', { name: 'Submit requisition' }).click();
  await expect(page.getByRole('status')).toContainText('submitted to the governed approval queue');
  await expect(editor.locator('.status').filter({ hasText: /^Submitted$/ })).toBeVisible();
  await page.getByRole('button', { name: 'Sign out' }).click();

  await loginAndSelect(page, 'finance.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="PUR-REQ"]').click();
  await expect(page.getByRole('heading', { name: 'Requisition approval inbox' })).toBeVisible();

  const inbox = page.locator('.requisition-approval-inbox');
  const approvalButton = inbox.getByRole('button', { name: /^E2E-REQ-001\b/ });
  await expect(approvalButton).toBeVisible();
  await approvalButton.click();
  await editor.getByLabel('Approval reason').fill('Demand, budget, and required date confirmed.');
  await editor.getByRole('button', { name: 'Approve requisition' }).click();
  await expect(page.getByRole('status')).toContainText('Purchase requisition approved');
  await expect(editor.locator('.status').filter({ hasText: /^Approved$/ })).toBeVisible();
  await expect(editor).toContainText('Demo Finance Manager');
  await expect(approvalButton).toHaveCount(0);
  await page.getByRole('button', { name: 'Sign out' }).click();

  await loginAndSelect(page, 'operations.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="PUR-RFQ"]').click();
  await expect(page.getByRole('heading', { name: 'Supplier quotations' })).toBeVisible();

  await page.getByRole('button', { name: '+ New' }).click();
  const rfqEditor = page.locator('.requisition-editor');
  await rfqEditor.getByLabel('RFQ number').fill('E2E-RFQ-001');
  const requisitionOption = rfqEditor.getByLabel('Approved requisition').locator('option').filter({ hasText: 'E2E-REQ-001' });
  await rfqEditor.getByLabel('Approved requisition').selectOption(await requisitionOption.getAttribute('value') ?? '');
  await rfqEditor.getByLabel('Response due date').fill(responseDueDate);
  await rfqEditor.getByLabel(/Western Ingredients Pvt Ltd/).check();
  await rfqEditor.getByLabel(/Deccan Supply Cooperative/).check();
  await rfqEditor.getByLabel('Commercial instructions').fill('Quote landed INR cost and attach batch certificate details.');
  await rfqEditor.getByRole('button', { name: 'Create draft RFQ' }).click();
  await expect(rfqEditor.getByRole('status')).toContainText('Draft RFQ created from the approved requisition');
  await expect(rfqEditor.locator('.status').filter({ hasText: /^Draft$/ })).toBeVisible();

  await rfqEditor.getByRole('button', { name: 'Issue RFQ' }).click();
  await expect(rfqEditor.getByRole('status')).toContainText('RFQ issued to all selected suppliers');
  await expect(rfqEditor.locator('.status').filter({ hasText: /^Issued$/ })).toBeVisible();

  await recordQuote(page, rfqEditor, WESTERN_SUPPLIER, 'WEST-E2E-001', '48', westernDeliveryDate);
  await recordQuote(page, rfqEditor, DECCAN_SUPPLIER, 'DECCAN-E2E-001', '49', deccanDeliveryDate);
  const comparison = rfqEditor.locator('.comparison-table');
  await expect(comparison.locator('tbody tr')).toHaveCount(2);
  await comparison.locator('tbody tr').filter({ hasText: 'Western Ingredients Pvt Ltd' }).getByRole('button', { name: 'Select' }).click();
  await rfqEditor.getByRole('button', { name: 'Award selected quote' }).click();
  await expect(rfqEditor.getByRole('status')).toContainText('Supplier award recorded with comparison evidence');
  await expect(rfqEditor.locator('.detail-status .status').filter({ hasText: /^Awarded$/ })).toBeVisible();
  await expect(rfqEditor).toContainText('Lowest compliant on-time offer');

  await navigation.locator('[data-screen-code="PUR-PO"]').click();
  await expect(page.getByRole('heading', { name: 'Purchase orders' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  const orderEditor = page.locator('.requisition-editor');
  await orderEditor.getByLabel('Purchase-order number').fill('E2E-PO-001');
  const rfqOption = orderEditor.getByLabel('Awarded RFQ').locator('option').filter({ hasText: 'E2E-RFQ-001' });
  await orderEditor.getByLabel('Awarded RFQ').selectOption(await rfqOption.getAttribute('value') ?? '');
  await orderEditor.getByLabel('Order date').fill(requestedDate);
  await orderEditor.getByLabel('Delivery terms').fill('Deliver to the raw-material receiving bay.');
  await orderEditor.getByRole('button', { name: 'Create draft order' }).click();
  await expect(orderEditor.getByRole('status')).toContainText('Draft purchase order created from the awarded supplier quote');
  await expect(orderEditor).toContainText('revision 1');

  await orderEditor.getByRole('button', { name: 'Amend order' }).click();
  await orderEditor.getByLabel('Amendment reason').fill('Supplier confirmed a revised freight charge.');
  await orderEditor.getByRole('spinbutton', { name: 'Freight', exact: true }).fill('20');
  await orderEditor.getByRole('button', { name: 'Record amendment' }).click();
  await expect(orderEditor.getByRole('status')).toContainText('amendment recorded as a new immutable revision');
  await expect(orderEditor).toContainText('Revision 2');

  await orderEditor.getByRole('button', { name: 'Issue purchase order' }).click();
  await expect(orderEditor.getByRole('status')).toContainText('Purchase order issued to the selected supplier');
  await expect(orderEditor.locator('.status').filter({ hasText: /^Issued$/ })).toBeVisible();

  await orderEditor.getByLabel('Cancellation reason').fill('Supplier capacity changed before receipt scheduling.');
  await orderEditor.getByRole('button', { name: 'Cancel purchase order' }).click();
  await expect(orderEditor.getByRole('status')).toContainText('Purchase order cancelled with reason evidence');
  await expect(orderEditor.locator('.status').filter({ hasText: /^Cancelled$/ })).toBeVisible();
  await expect(orderEditor).toContainText('Supplier capacity changed before receipt scheduling.');
});

async function recordQuote(
  page: Page,
  editor: ReturnType<Page['locator']>,
  supplierId: string,
  quoteNumber: string,
  unitPrice: string,
  promisedDelivery: string,
): Promise<void> {
  await editor.getByRole('button', { name: 'Record supplier quote' }).click();
  await editor.getByLabel('Quote supplier').selectOption(supplierId);
  await editor.getByLabel('Supplier quote number').fill(quoteNumber);
  await editor.getByLabel('Quote date').fill(dateFromToday(0));
  await editor.getByLabel('Valid until').fill(dateFromToday(21));
  await editor.getByLabel('Promised delivery').fill(promisedDelivery);
  await editor.getByLabel('Line 1 unit price').fill(unitPrice);
  await editor.getByRole('button', { name: 'Record supplier quote' }).click();
  await expect(editor.getByRole('status')).toContainText('Supplier quote recorded and comparison refreshed');
  await expect(page.getByRole('heading', { name: 'Supplier quotations' })).toBeVisible();
}

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await signInAndSelect(page, email);
}

function dateFromToday(days: number): string {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
