import { randomUUID } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';

const RAW_ITEM = '00000000-0000-4000-8000-000000000603';
const WESTERN_SUPPLIER = '00000000-0000-4000-8000-000000000503';
const DECCAN_SUPPLIER = '00000000-0000-4000-8000-000000000504';

test('procure-to-pay receives, inspects, returns, matches, pays, and reconciles supplier stock', async ({ page }, testInfo) => {
  test.setTimeout(180_000);
  const refs = procureRefs(testInfo.retry);
  const dates = procureDates();

  await loginAndSelect(page, 'operations.user@qtfoods.local');
  const { purchaseOrderId, purchaseOrderLineId } = await createIssuedPurchaseOrder(page, refs, dates);

  let navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="INB-GATE"]').click();
  await expect(page.getByRole('heading', { name: 'Gate Entry' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  const editor = page.locator('.requisition-editor');
  await editor.getByLabel('Gate-entry number').fill(refs.gate);
  await selectByText(editor.getByLabel('Issued purchase order'), refs.po);
  await editor.getByLabel('Vehicle number').fill('MH12P2P001');
  await editor.getByLabel('Transporter').fill('Controlled Logistics');
  await editor.getByLabel('Supplier document').fill(refs.challan);
  await editor.getByRole('button', { name: 'Record arrival' }).click();
  await expect(editor.getByRole('status')).toContainText('Vehicle arrival recorded');

  await navigation.locator('[data-screen-code="INB-GRN"]').click();
  await expect(page.getByRole('heading', { name: 'GRN / Partial Receipt' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Arrived gate entry'), refs.gate);
  await editor.getByLabel('GRN number').fill(refs.grn);
  await editor.getByLabel('Line 1 received quantity').fill('10');
  await editor.getByLabel('Line 1 internal lot').fill(refs.lot);
  await editor.getByRole('button', { name: 'Create draft GRN' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft GRN created');
  await editor.getByRole('button', { name: 'Post document' }).click();
  await expect(editor.getByRole('status')).toContainText('GRN posted to Quality Hold');
  await expect(editor).toContainText('Incoming QC');

  await navigation.locator('[data-screen-code="QC-IN"]').click();
  await expect(page.getByRole('heading', { name: 'Incoming QC' })).toBeVisible();
  const qcRow = page.locator('.requisition-table tbody tr').filter({ hasText: refs.grn });
  await qcRow.getByRole('button', { name: 'Open' }).click();
  await editor.getByLabel('Line 1 accepted quantity').fill('8');
  await editor.getByLabel('Line 1 rejected quantity').fill('2');
  await editor.getByLabel('Rejection reason').fill('Moisture exceeded the incoming specification.');
  await editor.getByLabel('Inspection notes').fill('Sampling plan and certificate review completed.');
  await editor.getByRole('button', { name: 'Complete incoming QC' }).click();
  await expect(editor.getByRole('status')).toContainText('accepted/rejected stock posted');

  await navigation.locator('[data-screen-code="INB-RETURN"]').click();
  await expect(page.getByRole('heading', { name: 'Supplier Returns' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Rejected QC lot'), refs.lot);
  await editor.getByLabel('Return number').fill(refs.returnNumber);
  await editor.getByLabel('Return reason').fill('Return the rejected quantity to the originating supplier.');
  await editor.getByRole('button', { name: 'Create draft return' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft supplier return created');
  await editor.getByRole('button', { name: 'Post document' }).click();
  await expect(editor.getByRole('status')).toContainText('Rejected stock returned to the supplier');

  await logout(page);
  await loginAndSelect(page, 'finance.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="FIN-AP"]').click();
  await expect(page.getByRole('heading', { name: 'Accounts Payable' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Issued purchase order'), refs.po);
  await editor.getByLabel('AP number').fill(refs.ap);
  await editor.getByLabel('Supplier invoice').fill(refs.supplierInvoice);
  await editor.getByLabel('Invoice line 1 quantity').fill('8');
  await editor.getByLabel('Invoice line 1 unit price').fill('40');
  await editor.getByLabel('Invoice line 1 tax rate').fill('18');
  await editor.getByRole('button', { name: 'Create draft invoice' }).click();
  await expect(editor.getByRole('status')).toContainText('calculated tax');
  await editor.getByRole('button', { name: 'Run three-way match' }).click();
  await expect(editor.getByRole('status')).toContainText('Three-way match completed');
  await expect(editor.locator('.status').filter({ hasText: /^Matched$/ })).toBeVisible();
  await editor.getByRole('button', { name: 'Approve invoice' }).click();
  await expect(editor.getByRole('alert')).toContainText('Maker-checker control prevents the invoice creator');

  await logout(page);
  await loginAndSelect(page, 'admin.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="FIN-AP"]').click();
  await page.getByLabel('Search', { exact: true }).fill(refs.ap);
  await openRow(page, refs.ap);
  await editor.getByRole('button', { name: 'Approve invoice' }).click();
  await expect(editor.getByRole('status')).toContainText('Payable invoice approved');

  await logout(page);
  await loginAndSelect(page, 'finance.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="FIN-AP"]').click();
  await page.getByRole('button', { name: 'Payment proposals' }).click();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Approved invoice'), refs.ap);
  await editor.getByLabel('Proposal number').fill(refs.proposal);
  await editor.getByRole('button', { name: 'Create payment proposal' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft payment proposal created');
  await editor.getByRole('button', { name: 'Approve proposal' }).click();
  await expect(editor.getByRole('alert')).toContainText('Maker-checker control prevents the proposal creator');

  await logout(page);
  await loginAndSelect(page, 'admin.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="FIN-AP"]').click();
  await page.getByRole('button', { name: 'Payment proposals' }).click();
  await page.getByLabel('Search', { exact: true }).fill(refs.proposal);
  await openRow(page, refs.proposal);
  await editor.getByRole('button', { name: 'Approve proposal' }).click();
  await expect(editor.getByRole('status')).toContainText('Payment proposal approved');

  await logout(page);
  await loginAndSelect(page, 'finance.user@qtfoods.local');
  navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="FIN-AP"]').click();
  await page.getByRole('button', { name: 'Payment proposals' }).click();
  await page.getByLabel('Search', { exact: true }).fill(refs.proposal);
  await openRow(page, refs.proposal);
  await editor.getByLabel('Payment number').fill(refs.payment);
  await editor.getByLabel('Bank reference').fill(refs.bankReference);
  await editor.getByRole('button', { name: 'Post payment' }).click();
  await expect(editor.getByRole('status')).toContainText('Payment posted and allocated');
  await editor.getByLabel('Statement date').fill(dates.today);
  await editor.getByLabel('Statement reference').fill(refs.statementReference);
  await editor.getByLabel('Notes').fill('Bank statement UTR and value date agree.');
  await editor.getByRole('button', { name: 'Reconcile payment' }).click();
  await expect(editor.getByRole('status')).toContainText('Payment reconciled to the bank statement');
  await expect(editor.locator('.status').filter({ hasText: /^Reconciled$/ })).toBeVisible();

  const payment = await apiGet<{ payments: unknown[] }>(page, `/api/v1/finance/payables?payment_status=RECONCILED&q=${encodeURIComponent(refs.bankReference)}`);
  expect(payment.payments).toHaveLength(1);
  expect(purchaseOrderId).toBeTruthy();
  expect(purchaseOrderLineId).toBeTruthy();
});

async function createIssuedPurchaseOrder(page: Page, refs: ReturnType<typeof procureRefs>, dates: ReturnType<typeof procureDates>): Promise<{ purchaseOrderId: string; purchaseOrderLineId: string }> {
  const requisition = await apiPost<{ data: { id: string } }>(page, '/api/v1/procurement/requisitions', {
    requisition_number: refs.requisition, department: 'Manufacturing', purpose: 'Controlled procure-to-pay browser journey.',
    requested_date: dates.today, required_by_date: dates.requiredByDate, currency: 'INR',
    lines: [{ item_id: RAW_ITEM, quantity: '10', estimated_unit_cost: '50', notes: null }],
  });
  const submitted = await apiPost<{ data: { approval_request_id: string } }>(page, `/api/v1/procurement/requisitions/${requisition.data.id}/submit`, {}, 1);

  await logout(page);
  await loginAndSelect(page, 'finance.user@qtfoods.local');
  await apiPost(page, `/api/v1/procurement/requisition-approvals/${submitted.data.approval_request_id}/approve`, { reason: 'Budget and raw-material demand verified.' }, 1);
  await logout(page);
  await loginAndSelect(page, 'operations.user@qtfoods.local');

  const rfq = await apiPost<{ data: { id: string } }>(page, '/api/v1/procurement/rfqs', {
    rfq_number: refs.rfq, requisition_id: requisition.data.id, response_due_date: dates.responseDueDate,
    commercial_terms: 'Delivered INR pricing exclusive of recoverable GST.', supplier_ids: [WESTERN_SUPPLIER, DECCAN_SUPPLIER],
  });
  await apiPost(page, `/api/v1/procurement/rfqs/${rfq.data.id}/issue`, {}, 1);
  const detail = await apiGet<{ data: { lines: { id: string }[] } }>(page, `/api/v1/procurement/rfqs/${rfq.data.id}`);
  const quote = await apiPost<{ data: { quote_id: string } }>(page, `/api/v1/procurement/rfqs/${rfq.data.id}/quotes`, {
    supplier_party_id: WESTERN_SUPPLIER, quote_number: refs.quote, quote_date: dates.today,
    valid_until: dates.validUntil, promised_delivery_date: dates.promisedDeliveryDate, payment_terms_days: 30,
    freight_amount: '0', other_charges: '0', discount_amount: '0', notes: null,
    lines: [{ rfq_line_id: detail.data.lines[0]!.id, unit_price: '40', notes: null }],
  }, 2);
  await apiPost(page, `/api/v1/procurement/rfqs/${rfq.data.id}/award`, { supplier_quote_id: quote.data.quote_id, award_reason: null }, 3);
  const order = await apiPost<{ data: { id: string } }>(page, '/api/v1/procurement/purchase-orders', {
    po_number: refs.po, rfq_id: rfq.data.id, order_date: dates.today, incoterm_code: 'DAP',
    delivery_terms: 'Deliver to the controlled receiving bay.', notes: null,
  });
  await apiPost(page, `/api/v1/procurement/purchase-orders/${order.data.id}/issue`, {}, 1);
  const orderDetail = await apiGet<{ data: { lines: { id: string }[] } }>(page, `/api/v1/procurement/purchase-orders/${order.data.id}`);
  return { purchaseOrderId: order.data.id, purchaseOrderLineId: orderDetail.data.lines[0]!.id };
}

function procureRefs(retry: number) {
  const suffix = retry === 0 ? '001' : `R${retry}`;
  return {
    requisition: `E2E-P2P-REQ-${suffix}`,
    rfq: `E2E-P2P-RFQ-${suffix}`,
    quote: `WEST-E2E-P2P-QUOTE-${suffix}`,
    po: `E2E-P2P-PO-${suffix}`,
    gate: `E2E-P2P-GATE-${suffix}`,
    challan: `E2E-P2P-CHALLAN-${suffix}`,
    grn: `E2E-P2P-GRN-${suffix}`,
    lot: `LOT-E2E-P2P-${suffix}`,
    returnNumber: `E2E-P2P-SRT-${suffix}`,
    ap: `E2E-P2P-AP-${suffix}`,
    supplierInvoice: `WEST-E2E-P2P-INV-${suffix}`,
    proposal: `E2E-P2P-PROP-${suffix}`,
    payment: `E2E-P2P-PAY-${suffix}`,
    bankReference: `UTR-E2E-P2P-${suffix}`,
    statementReference: `STMT-E2E-P2P-${suffix}`,
  };
}

function procureDates() {
  return {
    today: isoDate(0),
    responseDueDate: isoDate(7),
    promisedDeliveryDate: isoDate(14),
    requiredByDate: isoDate(21),
    validUntil: isoDate(30),
  };
}

function isoDate(offsetDays: number): string {
  const date = new Date();
  date.setUTCHours(12, 0, 0, 0);
  date.setUTCDate(date.getUTCDate() + offsetDays);
  return date.toISOString().slice(0, 10);
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
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Idempotency-Key': idempotencyKey };
    if (expectedVersion !== undefined) headers['If-Match'] = String(expectedVersion);
    const response = await fetch(requestPath, { method: 'POST', credentials: 'include', headers, body: JSON.stringify(payload) });
    return { ok: response.ok, status: response.status, body: await response.json() };
  }, { requestPath: path, payload: body, token: csrf.data.csrf_token, idempotencyKey: randomUUID(), expectedVersion: version });
  if (!result.ok) throw new Error(`POST ${path} failed (${result.status}): ${JSON.stringify(result.body)}`);
  return result.body as T;
}

async function openRow(page: Page, text: string): Promise<void> {
  const row = page.locator('.requisition-table tbody tr').filter({ hasText: text });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Open' }).click();
}

async function selectByText(select: ReturnType<Page['locator']>, text: string): Promise<void> {
  const option = select.locator('option').filter({ hasText: text });
  await select.selectOption(await option.getAttribute('value') ?? '');
}

async function loginAndSelect(page: Page, email: string): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill('prototype');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('button', { name: /Training Plant/ }).click();
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}
