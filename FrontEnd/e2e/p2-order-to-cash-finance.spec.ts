import { randomUUID } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';

const CUSTOMER = '00000000-0000-4000-8000-000000000501';
const ITEM = '00000000-0000-4000-8000-000000000601';
const CONTRACT = '00000000-0000-4000-8000-000000002501';
const EXPENSE_ACCOUNT = '00000000-0000-4000-8000-000000002013';
const REVENUE_ACCOUNT = '00000000-0000-4000-8000-000000002011';

test('P2 runs order-to-cash, finance simulation, private archive, and diagnostics through live workspaces', async ({ page }, testInfo) => {
  test.setTimeout(420_000);
  const refs = commercialRefs(testInfo.retry);
  const today = isoDate(0);
  await loginAndSelect(page, 'demo.user@qtfoods.local');

  await openP2Module(page, 'CRM-LEAD', 'Leads & Enquiries');
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await submitForm(page, 'New lead', {
    lead_number: refs.lead,
    customer_party_id: CUSTOMER,
    company_name: 'North Market Distributor',
    contact_name: 'P2 Browser Desk',
    contact_email: 'p2-browser@north-market.example',
    contact_phone: null,
    source: 'DIRECT',
    enquiry_date: today,
    expected_close_date: isoDate(10),
    estimated_value: '25000',
    notes: 'Playwright P2 commercial journey.',
  });
  await expect(page.getByRole('status')).toContainText('New lead saved successfully. Current status: New.');
  await searchAndOpen(page, 'Leads & Enquiries', refs.lead);
  await page.getByRole('button', { name: 'Qualify', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Lead qualified');
  await page.getByRole('button', { name: 'Convert', exact: true }).click();
  await submitForm(page, 'Convert lead', { customer_party_id: CUSTOMER });
  await expect(page.getByRole('status')).toContainText('Convert lead saved successfully. Current status: Converted.');
  const lead = first(await apiGet<P2List>(page, `/api/v1/sales/leads?q=${encodeURIComponent(refs.lead)}`));

  await openP2Module(page, 'CRM-ORDER', 'Sales Orders');
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await submitForm(page, 'New sales order', {
    order_number: refs.order,
    customer_party_id: CUSTOMER,
    sales_lead_id: lead.id,
    sales_contract_id: CONTRACT,
    sales_price_list_id: null,
    order_date: today,
    requested_delivery_date: isoDate(4),
    notes: 'Contract-backed browser order.',
    lines: [{ item_id: ITEM, uom_code: 'PACK', quantity: '2', discount_percent: '0' }],
  });
  await expect(page.getByRole('status')).toContainText('New sales order saved successfully. Current status: Draft.');
  await searchAndOpen(page, 'Sales Orders', refs.order);
  await page.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Sales order confirmed');
  const order = first(await apiGet<P2List>(page, `/api/v1/sales/orders?q=${encodeURIComponent(refs.order)}`));

  const allocation = await apiPost<P2Command>(page, `/api/v1/dispatch/orders/${order.id}/allocations`, {
    allocation_number: refs.allocation,
  }, Number(order.record_version));

  // Physical dispatch execution belongs to Operations, not Sales.
  await logout(page);
  await loginAndSelect(page, 'operations.user@qtfoods.local');
  await apiPost(page, `/api/v1/dispatch/allocations/${allocation.data.id}/pick`, {}, 1);
  const shipment = await apiPost<P2Command>(page, '/api/v1/dispatch/shipments', {
    shipment_number: refs.shipment,
    sales_allocation_id: allocation.data.id,
    carrier_name: 'Q&T Contract Logistics',
    vehicle_number: 'MH01E2E2001',
    driver_name: 'P2 Browser Driver',
    notes: 'Sealed browser-test load.',
  });
  await apiPost(page, `/api/v1/dispatch/shipments/${shipment.data.id}/load`, {}, 1);
  const dispatched = await apiPost<P2Command>(page, `/api/v1/dispatch/shipments/${shipment.data.id}/dispatch`, {
    invoice_number: refs.invoice,
  }, 2);
  const shipmentDetail = await apiGet<{ data: { lines: Array<{ id: string }> } }>(page, `/api/v1/dispatch/shipments/${shipment.data.id}`);
  await apiPost(page, `/api/v1/dispatch/shipments/${shipment.data.id}/pod`, {
    proof_number: refs.pod,
    outcome: 'DELIVERED',
    receiver_name: 'North Market Receiving',
    event_at: new Date().toISOString(),
    failure_reason: null,
    notes: 'Delivery accepted.',
  }, 3);

  // Commercial claim handling returns to Sales after Operations completes delivery.
  await logout(page);
  await loginAndSelect(page, 'demo.user@qtfoods.local');
  const claim = await apiPost<P2Command>(page, '/api/v1/sales/customer-claims', {
    claim_number: refs.claim,
    shipment_id: shipment.data.id,
    claim_type: 'DAMAGE',
    requested_resolution: 'CREDIT',
    reason: 'One delivered pack had transit damage.',
    lines: [{ shipment_line_id: shipmentDetail.data.lines[0]!.id, quantity: '1' }],
  });
  await apiPost(page, `/api/v1/sales/customer-claims/${claim.data.id}/resolve`, {
    resolution_type: 'CREDIT',
    credit_amount: '118',
    notes: 'Commercial credit approved from the governed claim.',
  }, 1);
  const receivable = await apiGet<{ data: { outstanding_amount: string | number } }>(page, `/api/v1/finance/receivables/${dispatched.data.invoice_id}`);
  const outstanding = String(receivable.data.outstanding_amount);
  await apiPost(page, '/api/v1/finance/receivables/collections', {
    receipt_number: refs.receipt,
    customer_party_id: CUSTOMER,
    receipt_date: today,
    payment_method: 'BANK',
    bank_reference: refs.bank,
    total_amount: outstanding,
    allocations: [{ invoice_id: dispatched.data.invoice_id, amount: outstanding }],
  });

  const commercialScreens: Array<[string, string]> = [
    ['CRM-LEAD', 'Leads & Enquiries'],
    ['CRM-PRICE', 'Pricing, Credit & Contracts'],
    ['CRM-ORDER', 'Sales Orders'],
    ['CON-WORK', 'Third-party Work'],
    ['DSP-PICK', 'Allocation & Picking'],
    ['DSP-LOAD', 'Loading & Dispatch'],
    ['DSP-POD', 'Proof of Delivery'],
    ['RET-CASE', 'Customer Claims & Returns'],
    ['FIN-AR', 'Receivables & Collections'],
    ['BI-PROFIT', 'Order Profitability'],
  ];
  for (const [code, heading] of commercialScreens) await openP2Module(page, code, heading);
  await page.getByLabel('Order Profitability search').fill(refs.order);
  await expect(page.locator('.p2-register tbody')).toContainText(refs.order);

  await logout(page);
  await loginAndSelect(page, 'finance.user@qtfoods.local');

  await openP2Module(page, 'FIN-SIM', 'Finance Simulation');
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await submitForm(page, 'New simulation', {
    simulation_number: refs.simulation,
    name: 'P2 browser margin scenario',
    description: 'An isolated browser-entered finance scenario.',
    as_of_date: today,
    lines: [
      { account_id: REVENUE_ACCOUNT, description: 'Projected revenue', debit_amount: '0', credit_amount: '1000', assumption: 'Contract sales delivered.' },
      { account_id: EXPENSE_ACCOUNT, description: 'Projected expense', debit_amount: '200', credit_amount: '0', assumption: 'Incremental selling cost.' },
    ],
  });
  await expect(page.getByRole('status')).toContainText('New simulation saved successfully. Current status: Draft.');
  await searchAndOpen(page, 'Finance Simulation', refs.simulation);
  await page.getByRole('button', { name: 'Run', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Simulation run completed with no ledger effect');

  await openP2Module(page, 'FIN-ARCH', 'Private Bill Archive');
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await page.getByLabel('Archive document number').fill(refs.document);
  await page.getByLabel('Archive document date').fill(today);
  await page.getByLabel('Archive retain until').fill(isoDate(365 * 7));
  await page.getByLabel('Notes').fill('Private P2 archive browser evidence.');
  await page.getByLabel('Archive private document').setInputFiles({
    name: 'e2e-p2-private-bill.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4 P2 browser private bill'),
  });
  await page.getByRole('button', { name: 'Upload privately' }).click();
  await expect(page.getByRole('status')).toContainText('Document archived with verified private metadata (ARCHIVED)');
  await page.getByLabel('Archive search').fill(refs.document);
  await openRow(page, refs.document);
  await expect(page.locator('.p2-checksum')).toHaveText(/^[a-f0-9]{64}$/);
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download private document' }).click();
  expect((await downloadPromise).suggestedFilename()).toBe('e2e-p2-private-bill.pdf');

  await openP2Module(page, 'FIN-SUP', 'Finance Support & Diagnostics');
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  await submitForm(page, 'New support & diagnostics', {
    case_number: refs.support,
    category: 'RECONCILIATION',
    severity: 'HIGH',
    subject: 'P2 browser diagnostic request',
    description: 'Capture finance counters without mutating ledger records.',
  });
  await expect(page.getByRole('status')).toContainText('New support & diagnostics saved successfully. Current status: Open.');
  await searchAndOpen(page, 'Finance Support & Diagnostics', refs.support);
  await page.getByRole('button', { name: 'Diagnose', exact: true }).click();
  await submitForm(page, 'Capture diagnostic snapshot', { notes: 'Browser verification captured the immutable counters.' });
  await expect(page.getByRole('status')).toContainText('Capture diagnostic snapshot saved successfully. Current status: Diagnosed.');
  await searchAndOpen(page, 'Finance Support & Diagnostics', refs.support);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await submitForm(page, 'Close finance support case', { resolution_notes: 'P2 browser diagnostics verified.' });
  await expect(page.getByRole('status')).toContainText('Close finance support case saved successfully. Current status: Closed.');

  const financeScreens: Array<[string, string]> = [
    ['FIN-EXP', 'Employee Expenses'],
    ['FIN-GL', 'General Ledger & Period Close'],
    ['COST-OH', 'Overhead Allocation'],
    ['ASSET-REG', 'Fixed Asset Register'],
    ['HR-PAY', 'Payroll Posting'],
    ['ENG-MNT', 'Maintenance Accounting'],
    ['FIN-SIM', 'Finance Simulation'],
    ['FIN-ADJ', 'Finance Adjustments'],
    ['FIN-LEGACY', 'Historical Import'],
    ['FIN-ARCH', 'Private Bill Archive'],
    ['FIN-OPEN', 'Opening Balance Reconciliation'],
    ['FIN-SUP', 'Finance Support & Diagnostics'],
  ];
  for (const [code, heading] of financeScreens) await openP2Module(page, code, heading);

  await openModule(page, 'FIN-AP', 'Accounts Payable');
  await page.getByRole('button', { name: 'Bank & statutory integrations' }).click();
  await expect(page.getByRole('heading', { name: 'Payables Bank & Statutory Integrations' })).toBeVisible();
  await expect(page.locator('.p2-live-notice')).toBeVisible();
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
});

function commercialRefs(retry: number) {
  const suffix = retry === 0 ? '001' : `R${retry}-${Date.now().toString(36).toUpperCase()}`;
  return {
    lead: `E2E-P2-LEAD-${suffix}`,
    order: `E2E-P2-SO-${suffix}`,
    allocation: `E2E-P2-ALLOC-${suffix}`,
    shipment: `E2E-P2-SHP-${suffix}`,
    invoice: `E2E-P2-INV-${suffix}`,
    pod: `E2E-P2-POD-${suffix}`,
    claim: `E2E-P2-CLM-${suffix}`,
    receipt: `E2E-P2-RCPT-${suffix}`,
    bank: `E2E-P2-BANK-${suffix}`,
    simulation: `E2E-P2-SIM-${suffix}`,
    document: `E2E-P2-DOC-${suffix}`,
    support: `E2E-P2-FS-${suffix}`,
  };
}

function isoDate(offsetDays: number): string {
  const date = new Date();
  date.setUTCHours(12, 0, 0, 0);
  date.setUTCDate(date.getUTCDate() + offsetDays);
  return date.toISOString().slice(0, 10);
}

type P2List = { data: Array<{ id: string; record_version?: number }> };
type P2Command = { data: { id: string; record_version: number; invoice_id?: string } };

function first(result: P2List) {
  const record = result.data[0];
  if (!record) throw new Error('Expected the P2 register to contain a matching record.');
  return record;
}

async function openP2Module(page: Page, code: string, heading: string): Promise<void> {
  await openModule(page, code, heading);
  await expect(page.locator('.p2-live-notice')).toBeVisible();
  await expect(page.getByText('PROTOTYPE / DEMO DATA')).toHaveCount(0);
}

async function openModule(page: Page, code: string, heading: string): Promise<void> {
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator(`[data-screen-code="${code}"]`).click();
  await expect(page.getByRole('heading', { name: heading })).toBeVisible();
}

async function submitForm(page: Page, label: string, body: unknown): Promise<void> {
  await expect(page.getByRole('heading', { name: label })).toBeVisible();
  await fillFormValue(page, body, '');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
}

async function fillFormValue(page: Page, value: unknown, path: string): Promise<void> {
  if (Array.isArray(value)) {
    const section = page.locator(`section[data-field-path="${path}"]`);
    if (await section.count()) {
      while (await section.locator('.p2-entry-line-card').count() < value.length) await section.getByRole('button', { name: /^\+ Add / }).click();
      while (await section.locator('.p2-entry-line-card').count() > value.length) await section.locator('.p2-entry-line-card').last().getByRole('button', { name: 'Remove' }).click();
    }
    for (let index = 0; index < value.length; index += 1) await fillFormValue(page, value[index], `${path}.${index}`);
    return;
  }
  if (value !== null && typeof value === 'object') {
    for (const [key, item] of Object.entries(value)) await fillFormValue(page, item, path ? `${path}.${key}` : key);
    return;
  }

  const control = page.locator(`[data-field-path="${path}"]`);
  if (!await control.count()) {
    if (value === null) return;
    throw new Error(`No form field was rendered for ${path}.`);
  }
  const details = await control.first().evaluate((element) => ({
    tag: element.tagName.toLowerCase(),
    type: element instanceof HTMLInputElement ? element.type : '',
    readOnly: element instanceof HTMLInputElement ? element.readOnly : false,
  }));
  if (details.readOnly) return;
  if (details.tag === 'select') { await control.first().selectOption(value === null ? '' : String(value)); return; }
  if (details.type === 'checkbox') {
    if (Boolean(value)) await control.first().check(); else await control.first().uncheck();
    return;
  }
  const text = value === null ? '' : String(value);
  await control.first().fill(details.type === 'datetime-local' ? text.replace(/Z$/, '').slice(0, 16) : text);
}

async function searchAndOpen(page: Page, title: string, text: string): Promise<void> {
  await page.getByLabel(`${title} search`).fill(text);
  await openRow(page, text);
}

async function openRow(page: Page, text: string): Promise<void> {
  const row = page.locator('.requisition-table tbody tr').filter({ hasText: text });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: 'Open' }).click();
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
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
      'Idempotency-Key': idempotencyKey,
    };
    if (expectedVersion !== undefined) headers['If-Match'] = String(expectedVersion);
    const response = await fetch(requestPath, {
      method: 'POST', credentials: 'include', headers, body: JSON.stringify(payload),
    });
    return { ok: response.ok, status: response.status, body: await response.json() };
  }, { requestPath: path, payload: body, token: csrf.data.csrf_token, idempotencyKey: randomUUID(), expectedVersion: version });
  if (!result.ok) throw new Error(`POST ${path} failed (${result.status}): ${JSON.stringify(result.body)}`);
  return result.body as T;
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
